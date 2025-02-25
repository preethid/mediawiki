<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Used to show archived pages and eventually restore them.
 */
class PageArchive {

	/** @var Title */
	protected $title;

	/** @var Status|null */
	protected $fileStatus;

	/** @var Status|null */
	protected $revisionStatus;

	/** @var Config */
	protected $config;

	/** @var HookRunner */
	private $hookRunner;

	/** @var JobQueueGroup */
	private $jobQueueGroup;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var LoggerInterface */
	private $logger;

	/** @var ReadOnlyMode */
	private $readOnlyMode;

	/** @var RepoGroup */
	private $repoGroup;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var UserFactory */
	private $userFactory;

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/**
	 * @param Title $title
	 * @param Config|null $config
	 */
	public function __construct( Title $title, Config $config = null ) {
		$this->title = $title;

		$this->logger = LoggerFactory::getInstance( 'PageArchive' );

		$services = MediaWikiServices::getInstance();
		if ( $config === null ) {
			// TODO deprecate not passing a Config object, though technically this class is
			// not @newable / stable to create
			$this->logger->debug( 'Constructor did not have a Config object passed to it' );
			$config = $services->getMainConfig();
		}
		$this->config = $config;

		$this->hookRunner = new HookRunner( $services->getHookContainer() );
		$this->jobQueueGroup = $services->getJobQueueGroup();
		$this->loadBalancer = $services->getDBLoadBalancer();
		$this->readOnlyMode = $services->getReadOnlyMode();
		$this->repoGroup = $services->getRepoGroup();

		// TODO: Refactor: delete()/undeleteAsUser() should live in a PageStore service;
		//       Methods in PageArchive and RevisionStore that deal with archive revisions
		//       should move into an ArchiveStore service (but could still be implemented
		//       together with RevisionStore).
		$this->revisionStore = $services->getRevisionStore();

		$this->userFactory = $services->getUserFactory();
		$this->wikiPageFactory = $services->getWikiPageFactory();
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * List deleted pages recorded in the archive matching the
	 * given term, using search engine archive.
	 * Returns result wrapper with (ar_namespace, ar_title, count) fields.
	 *
	 * @param string $term Search term
	 * @return IResultWrapper|bool
	 */
	public static function listPagesBySearch( $term ) {
		$title = Title::newFromText( $term );
		if ( $title ) {
			$ns = $title->getNamespace();
			$termMain = $title->getText();
			$termDb = $title->getDBkey();
		} else {
			// Prolly won't work too good
			// @todo handle bare namespace names cleanly?
			$ns = 0;
			$termMain = $termDb = $term;
		}

		// Try search engine first
		$engine = MediaWikiServices::getInstance()->newSearchEngine();
		$engine->setLimitOffset( 100 );
		$engine->setNamespaces( [ $ns ] );
		$results = $engine->searchArchiveTitle( $termMain );
		if ( !$results->isOK() ) {
			$results = [];
		} else {
			$results = $results->getValue();
		}

		if ( !$results ) {
			// Fall back to regular prefix search
			return self::listPagesByPrefix( $term );
		}

		$dbr = wfGetDB( DB_REPLICA );
		$condTitles = array_unique( array_map( static function ( Title $t ) {
			return $t->getDBkey();
		}, $results ) );
		$conds = [
			'ar_namespace' => $ns,
			$dbr->makeList( [ 'ar_title' => $condTitles ], LIST_OR ) . " OR ar_title " .
			$dbr->buildLike( $termDb, $dbr->anyString() )
		];

		return self::listPages( $dbr, $conds );
	}

	/**
	 * List deleted pages recorded in the archive table matching the
	 * given title prefix.
	 * Returns result wrapper with (ar_namespace, ar_title, count) fields.
	 *
	 * @param string $prefix Title prefix
	 * @return IResultWrapper|bool
	 */
	public static function listPagesByPrefix( $prefix ) {
		$dbr = wfGetDB( DB_REPLICA );

		$title = Title::newFromText( $prefix );
		if ( $title ) {
			$ns = $title->getNamespace();
			$prefix = $title->getDBkey();
		} else {
			// Prolly won't work too good
			// @todo handle bare namespace names cleanly?
			$ns = 0;
		}

		$conds = [
			'ar_namespace' => $ns,
			'ar_title' . $dbr->buildLike( $prefix, $dbr->anyString() ),
		];

		return self::listPages( $dbr, $conds );
	}

	/**
	 * @param IDatabase $dbr
	 * @param string|array $condition
	 * @return bool|IResultWrapper
	 */
	protected static function listPages( $dbr, $condition ) {
		return $dbr->select(
			[ 'archive' ],
			[
				'ar_namespace',
				'ar_title',
				'count' => 'COUNT(*)'
			],
			$condition,
			__METHOD__,
			[
				'GROUP BY' => [ 'ar_namespace', 'ar_title' ],
				'ORDER BY' => [ 'ar_namespace', 'ar_title' ],
				'LIMIT' => 100,
			]
		);
	}

	/**
	 * List the revisions of the given page. Returns result wrapper with
	 * various archive table fields.
	 *
	 * @return IResultWrapper|bool
	 */
	public function listRevisions() {
		$queryInfo = $this->revisionStore->getArchiveQueryInfo();

		$conds = [
			'ar_namespace' => $this->title->getNamespace(),
			'ar_title' => $this->title->getDBkey(),
		];

		// NOTE: ordering by ar_timestamp and ar_id, to remove ambiguity.
		// XXX: Ideally, we would be ordering by ar_timestamp and ar_rev_id, but since we
		// don't have an index on ar_rev_id, that causes a file sort.
		$options = [ 'ORDER BY' => [ 'ar_timestamp DESC', 'ar_id DESC' ] ];

		ChangeTags::modifyDisplayQuery(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$conds,
			$queryInfo['joins'],
			$options,
			''
		);

		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		return $dbr->select(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$conds,
			__METHOD__,
			$options,
			$queryInfo['joins']
		);
	}

	/**
	 * List the deleted file revisions for this page, if it's a file page.
	 * Returns a result wrapper with various filearchive fields, or null
	 * if not a file page.
	 *
	 * @return IResultWrapper|null
	 * @todo Does this belong in Image for fuller encapsulation?
	 */
	public function listFiles() {
		if ( $this->title->getNamespace() !== NS_FILE ) {
			return null;
		}

		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$fileQuery = ArchivedFile::getQueryInfo();
		return $dbr->select(
			$fileQuery['tables'],
			$fileQuery['fields'],
			[ 'fa_name' => $this->title->getDBkey() ],
			__METHOD__,
			[ 'ORDER BY' => 'fa_timestamp DESC' ],
			$fileQuery['joins']
		);
	}

	/**
	 * Return a RevisionRecord object containing data for the deleted revision.
	 *
	 * @internal only for use in SpecialUndelete
	 *
	 * @param string $timestamp
	 * @return RevisionRecord|null
	 */
	public function getRevisionRecordByTimestamp( $timestamp ) {
		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$rec = $this->getRevisionByConditions(
			[ 'ar_timestamp' => $dbr->timestamp( $timestamp ) ]
		);
		return $rec;
	}

	/**
	 * Return the archived revision with the given ID.
	 *
	 * @since 1.35
	 *
	 * @param int $revId
	 * @return RevisionRecord|null
	 */
	public function getArchivedRevisionRecord( int $revId ) {
		return $this->getRevisionByConditions( [ 'ar_rev_id' => $revId ] );
	}

	/**
	 * @param array $conditions
	 * @param array $options
	 *
	 * @return RevisionRecord|null
	 */
	private function getRevisionByConditions( array $conditions, array $options = [] ) {
		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$arQuery = $this->revisionStore->getArchiveQueryInfo();

		$conditions += [
			'ar_namespace' => $this->title->getNamespace(),
			'ar_title' => $this->title->getDBkey(),
		];

		$row = $dbr->selectRow(
			$arQuery['tables'],
			$arQuery['fields'],
			$conditions,
			__METHOD__,
			$options,
			$arQuery['joins']
		);

		if ( $row ) {
			return $this->revisionStore->newRevisionFromArchiveRow( $row, 0, $this->title );
		}

		return null;
	}

	/**
	 * Return the most-previous revision, either live or deleted, against
	 * the deleted revision given by timestamp.
	 *
	 * May produce unexpected results in case of history merges or other
	 * unusual time issues.
	 *
	 * @since 1.35
	 *
	 * @param string $timestamp
	 * @return RevisionRecord|null Null when there is no previous revision
	 */
	public function getPreviousRevisionRecord( string $timestamp ) {
		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );

		// Check the previous deleted revision...
		$row = $dbr->selectRow( 'archive',
			[ 'ar_rev_id', 'ar_timestamp' ],
			[ 'ar_namespace' => $this->title->getNamespace(),
				'ar_title' => $this->title->getDBkey(),
				'ar_timestamp < ' .
				$dbr->addQuotes( $dbr->timestamp( $timestamp ) ) ],
			__METHOD__,
			[
				'ORDER BY' => 'ar_timestamp DESC',
			] );
		$prevDeleted = $row ? wfTimestamp( TS_MW, $row->ar_timestamp ) : false;
		$prevDeletedId = $row ? intval( $row->ar_rev_id ) : null;

		$row = $dbr->selectRow( [ 'page', 'revision' ],
			[ 'rev_id', 'rev_timestamp' ],
			[
				'page_namespace' => $this->title->getNamespace(),
				'page_title' => $this->title->getDBkey(),
				'page_id = rev_page',
				'rev_timestamp < ' .
				$dbr->addQuotes( $dbr->timestamp( $timestamp ) ) ],
			__METHOD__,
			[
				'ORDER BY' => 'rev_timestamp DESC',
			] );
		$prevLive = $row ? wfTimestamp( TS_MW, $row->rev_timestamp ) : false;
		$prevLiveId = $row ? intval( $row->rev_id ) : null;

		if ( $prevLive && $prevLive > $prevDeleted ) {
			// Most prior revision was live
			$rec = $this->revisionStore->getRevisionById( $prevLiveId );
		} elseif ( $prevDeleted ) {
			// Most prior revision was deleted
			$rec = $this->getArchivedRevisionRecord( $prevDeletedId );
		} else {
			$rec = null;
		}

		return $rec;
	}

	/**
	 * Returns the ID of the latest deleted revision.
	 *
	 * @return int|false The revision's ID, or false if there is no deleted revision.
	 */
	public function getLastRevisionId() {
		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$revId = $dbr->selectField(
			'archive',
			'ar_rev_id',
			[ 'ar_namespace' => $this->title->getNamespace(),
				'ar_title' => $this->title->getDBkey() ],
			__METHOD__,
			[ 'ORDER BY' => [ 'ar_timestamp DESC', 'ar_id DESC' ] ]
		);

		return $revId ? intval( $revId ) : false;
	}

	/**
	 * Quick check if any archived revisions are present for the page.
	 * This says nothing about whether the page currently exists in the page table or not.
	 *
	 * @return bool
	 */
	public function isDeleted() {
		$dbr = $this->loadBalancer->getConnectionRef( DB_REPLICA );
		$row = $dbr->selectRow(
			[ 'archive' ],
			'1', // We don't care about the value. Allow the database to optimize.
			[ 'ar_namespace' => $this->title->getNamespace(),
				'ar_title' => $this->title->getDBkey() ],
			__METHOD__
		);

		return (bool)$row;
	}

	/**
	 * Restore the given (or all) text and file revisions for the page.
	 * Once restored, the items will be removed from the archive tables.
	 * The deletion log will be updated with an undeletion notice.
	 *
	 * This also sets Status objects, $this->fileStatus and $this->revisionStatus
	 * (depending what operations are attempted).
	 *
	 * @since 1.35
	 *
	 * @param array $timestamps Pass an empty array to restore all revisions,
	 *   otherwise list the ones to undelete.
	 * @param UserIdentity $user
	 * @param string $comment
	 * @param array $fileVersions
	 * @param bool $unsuppress
	 * @param string|string[]|null $tags Change tags to add to log entry
	 *   ($user should be able to add the specified tags before this is called)
	 * @return array|bool [ number of file revisions restored, number of image revisions
	 *   restored, log message ] on success, false on failure.
	 */
	public function undeleteAsUser(
		$timestamps,
		UserIdentity $user,
		$comment = '',
		$fileVersions = [],
		$unsuppress = false,
		$tags = null
	) {
		// If both the set of text revisions and file revisions are empty,
		// restore everything. Otherwise, just restore the requested items.
		$restoreAll = empty( $timestamps ) && empty( $fileVersions );

		$restoreText = $restoreAll || !empty( $timestamps );
		$restoreFiles = $restoreAll || !empty( $fileVersions );

		if ( $restoreFiles && $this->title->getNamespace() === NS_FILE ) {
			/** @var LocalFile $img */
			$img = $this->repoGroup->getLocalRepo()->newFile( $this->title );
			$img->load( File::READ_LATEST );
			$this->fileStatus = $img->restore( $fileVersions, $unsuppress );
			if ( !$this->fileStatus->isOK() ) {
				return false;
			}
			$filesRestored = $this->fileStatus->successCount;
		} else {
			$filesRestored = 0;
		}

		if ( $restoreText ) {
			$this->revisionStatus = $this->undeleteRevisions( $timestamps, $unsuppress, $comment );
			if ( !$this->revisionStatus->isOK() ) {
				return false;
			}

			$textRestored = $this->revisionStatus->getValue();
		} else {
			$textRestored = 0;
		}

		// Touch the log!

		if ( !$textRestored && !$filesRestored ) {
			$this->logger->debug( "Undelete: nothing undeleted..." );

			return false;
		}

		$logEntry = new ManualLogEntry( 'delete', 'restore' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $this->title );
		$logEntry->setComment( $comment );
		$logEntry->addTags( $tags );
		$logEntry->setParameters( [
			':assoc:count' => [
				'revisions' => $textRestored,
				'files' => $filesRestored,
			],
		] );

		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		return [ $textRestored, $filesRestored, $comment ];
	}

	/**
	 * This is the meaty bit -- It restores archived revisions of the given page
	 * to the revision table.
	 *
	 * @param array $timestamps Pass an empty array to restore all revisions,
	 *   otherwise list the ones to undelete.
	 * @param bool $unsuppress Remove all ar_deleted/fa_deleted restrictions of seletected revs
	 * @param string $comment
	 * @throws ReadOnlyError
	 * @return Status Status object containing the number of revisions restored on success
	 */
	private function undeleteRevisions( $timestamps, $unsuppress = false, $comment = '' ) {
		if ( $this->readOnlyMode->isReadOnly() ) {
			throw new ReadOnlyError();
		}

		$dbw = $this->loadBalancer->getConnectionRef( DB_PRIMARY );
		$dbw->startAtomic( __METHOD__, IDatabase::ATOMIC_CANCELABLE );

		$restoreAll = empty( $timestamps );

		$oldWhere = [
			'ar_namespace' => $this->title->getNamespace(),
			'ar_title' => $this->title->getDBkey(),
		];
		if ( !$restoreAll ) {
			$oldWhere['ar_timestamp'] = array_map( [ &$dbw, 'timestamp' ], $timestamps );
		}

		$revisionStore = $this->revisionStore;
		$queryInfo = $revisionStore->getArchiveQueryInfo();
		$queryInfo['tables'][] = 'revision';
		$queryInfo['fields'][] = 'rev_id';
		$queryInfo['joins']['revision'] = [ 'LEFT JOIN', 'ar_rev_id=rev_id' ];

		/**
		 * Select each archived revision...
		 */
		$result = $dbw->select(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$oldWhere,
			__METHOD__,
			/* options */
			[ 'ORDER BY' => 'ar_timestamp' ],
			$queryInfo['joins']
		);

		$rev_count = $result->numRows();
		if ( !$rev_count ) {
			$this->logger->debug( __METHOD__ . ": no revisions to restore" );

			$status = Status::newGood( 0 );
			$status->warning( "undelete-no-results" );
			$dbw->endAtomic( __METHOD__ );

			return $status;
		}

		// We use ar_id because there can be duplicate ar_rev_id even for the same
		// page.  In this case, we may be able to restore the first one.
		$restoreFailedArIds = [];

		// Map rev_id to the ar_id that is allowed to use it.  When checking later,
		// if it doesn't match, the current ar_id can not be restored.

		// Value can be an ar_id or -1 (-1 means no ar_id can use it, since the
		// rev_id is taken before we even start the restore).
		$allowedRevIdToArIdMap = [];

		$latestRestorableRow = null;

		foreach ( $result as $row ) {
			if ( $row->ar_rev_id ) {
				// rev_id is taken even before we start restoring.
				if ( $row->ar_rev_id === $row->rev_id ) {
					$restoreFailedArIds[] = $row->ar_id;
					$allowedRevIdToArIdMap[$row->ar_rev_id] = -1;
				} else {
					// rev_id is not taken yet in the DB, but it might be taken
					// by a prior revision in the same restore operation. If
					// not, we need to reserve it.
					if ( isset( $allowedRevIdToArIdMap[$row->ar_rev_id] ) ) {
						$restoreFailedArIds[] = $row->ar_id;
					} else {
						$allowedRevIdToArIdMap[$row->ar_rev_id] = $row->ar_id;
						$latestRestorableRow = $row;
					}
				}
			} else {
				// If ar_rev_id is null, there can't be a collision, and a
				// rev_id will be chosen automatically.
				$latestRestorableRow = $row;
			}
		}

		$result->seek( 0 ); // move back

		$article = $this->wikiPageFactory->newFromTitle( $this->title );

		$oldPageId = 0;
		/** @var RevisionRecord|null $revision */
		$revision = null;
		$created = true;
		$oldcountable = false;
		$updatedCurrentRevision = false;
		$restored = 0; // number of revisions restored
		$restoredPages = [];

		// If there are no restorable revisions, we can skip most of the steps.
		if ( $latestRestorableRow === null ) {
			$failedRevisionCount = $rev_count;
		} else {
			$oldPageId = (int)$latestRestorableRow->ar_page_id; // pass this to ArticleUndelete hook

			// Grab the content to check consistency with global state before restoring the page.
			// XXX: The only current use case is Wikibase, which tries to enforce uniqueness of
			// certain things across all pages. There may be a better way to do that.
			$revision = $revisionStore->newRevisionFromArchiveRow(
				$latestRestorableRow,
				0,
				$this->title
			);

			// TODO: use UserFactory::newFromUserIdentity from If610c68f4912e
			// TODO: The User isn't used for anything in prepareSave()! We should drop it.
			$user = $this->userFactory->newFromName(
				$revision->getUser( RevisionRecord::RAW )->getName(),
				UserFactory::RIGOR_NONE
			);

			foreach ( $revision->getSlotRoles() as $role ) {
				$content = $revision->getContent( $role, RevisionRecord::RAW );

				// NOTE: article ID may not be known yet. prepareSave() should not modify the database.
				$status = $content->prepareSave( $article, 0, -1, $user );
				if ( !$status->isOK() ) {
					$dbw->endAtomic( __METHOD__ );

					return $status;
				}
			}

			$pageId = $article->insertOn( $dbw, $latestRestorableRow->ar_page_id );
			if ( $pageId === false ) {
				// The page ID is reserved; let's pick another
				$pageId = $article->insertOn( $dbw );
				if ( $pageId === false ) {
					// The page title must be already taken (race condition)
					$created = false;
				}
			}

			# Does this page already exist? We'll have to update it...
			if ( !$created ) {
				# Load latest data for the current page (T33179)
				$article->loadPageData( WikiPage::READ_EXCLUSIVE );
				$pageId = $article->getId();
				$oldcountable = $article->isCountable();

				$previousTimestamp = false;
				$latestRevId = $article->getLatest();
				if ( $latestRevId ) {
					$previousTimestamp = $revisionStore->getTimestampFromId(
						$latestRevId,
						RevisionStore::READ_LATEST
					);
				}
				if ( $previousTimestamp === false ) {
					$this->logger->debug( __METHOD__ . ": existing page refers to a page_latest that does not exist" );

					$status = Status::newGood( 0 );
					$status->warning( 'undeleterevision-missing' );
					$dbw->cancelAtomic( __METHOD__ );

					return $status;
				}
			} else {
				$previousTimestamp = 0;
			}

			// Check if a deleted revision will become the current revision...
			if ( $latestRestorableRow->ar_timestamp > $previousTimestamp ) {
				// Check the state of the newest to-be version...
				if ( !$unsuppress
					&& ( $latestRestorableRow->ar_deleted & RevisionRecord::DELETED_TEXT )
				) {
					$dbw->cancelAtomic( __METHOD__ );

					return Status::newFatal( "undeleterevdel" );
				}
				$updatedCurrentRevision = true;
			}

			foreach ( $result as $row ) {
				// Check for key dupes due to needed archive integrity.
				if ( $row->ar_rev_id && $allowedRevIdToArIdMap[$row->ar_rev_id] !== $row->ar_id ) {
					continue;
				}
				// Insert one revision at a time...maintaining deletion status
				// unless we are specifically removing all restrictions...
				$revision = $revisionStore->newRevisionFromArchiveRow(
					$row,
					0,
					$this->title,
					[
						'page_id' => $pageId,
						'deleted' => $unsuppress ? 0 : $row->ar_deleted
					]
				);

				// This will also copy the revision to ip_changes if it was an IP edit.
				$revisionStore->insertRevisionOn( $revision, $dbw );

				$restored++;

				$this->hookRunner->onRevisionUndeleted( $revision, $row->ar_page_id );

				$restoredPages[$row->ar_page_id] = true;
			}

			// Now that it's safely stored, take it out of the archive
			// Don't delete rows that we failed to restore
			$toDeleteConds = $oldWhere;
			$failedRevisionCount = count( $restoreFailedArIds );
			if ( $failedRevisionCount > 0 ) {
				$toDeleteConds[] = 'ar_id NOT IN ( ' . $dbw->makeList( $restoreFailedArIds ) . ' )';
			}

			$dbw->delete( 'archive',
				$toDeleteConds,
				__METHOD__ );
		}

		$status = Status::newGood( $restored );

		if ( $failedRevisionCount > 0 ) {
			$status->warning(
				wfMessage( 'undeleterevision-duplicate-revid', $failedRevisionCount ) );
		}

		// Was anything restored at all?
		if ( $restored ) {

			if ( $updatedCurrentRevision ) {
				// Attach the latest revision to the page...
				// XXX: updateRevisionOn should probably move into a PageStore service.
				$wasnew = $article->updateRevisionOn(
					$dbw,
					$revision,
					$created ? 0 : $article->getLatest()
				);
			} else {
				$wasnew = false;
			}

			if ( $created || $wasnew ) {
				// Update site stats, link tables, etc
				// TODO: use DerivedPageDataUpdater from If610c68f4912e!
				// TODO use UserFactory::newFromUserIdentity
				$article->doEditUpdates(
					$revision,
					$revision->getUser( RevisionRecord::RAW ),
					[
						'created' => $created,
						'oldcountable' => $oldcountable,
						'restored' => true
					]
				);
			}

			$this->hookRunner->onArticleUndelete(
				$this->title, $created, $comment, $oldPageId, $restoredPages );

			if ( $this->title->getNamespace() === NS_FILE ) {
				$job = HTMLCacheUpdateJob::newForBacklinks(
					$this->title,
					'imagelinks',
					[ 'causeAction' => 'file-restore' ]
				);
				$this->jobQueueGroup->lazyPush( $job );
			}
		}

		$dbw->endAtomic( __METHOD__ );

		return $status;
	}

	/**
	 * @return Status|null
	 */
	public function getFileStatus() {
		return $this->fileStatus;
	}

	/**
	 * @return Status|null
	 */
	public function getRevisionStatus() {
		return $this->revisionStatus;
	}
}
