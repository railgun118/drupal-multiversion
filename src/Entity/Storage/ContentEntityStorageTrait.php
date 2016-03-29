<?php

/**
 * @file
 * Contains \Drupal\multiversion\Entity\Storage\ContentEntityStorageTrait.
 */

namespace Drupal\multiversion\Entity\Storage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\file\FileInterface;
use Drupal\user\UserStorageInterface;

trait ContentEntityStorageTrait {

  /**
   * @var boolean
   */
  protected $isDeleted = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getQueryServiceName() {
    return 'multiversion.entity.query.sql';
  }

  /**
   * {@inheritdoc}
   */
  protected function buildQuery($ids, $revision_id = FALSE) {
    $query = parent::buildQuery($ids, $revision_id);

    // Prevent to modify the query before entity type updates.
    if ($ids === NULL) {
      return $query;
    }

    $field_data_alias = 'base';
    $revision_data_alias = 'revision';
    if ($this->entityType->isTranslatable()) {
      // Join the field data table in order to set the workspace condition.
      $field_data_table = $this->getDataTable();
      $field_data_alias = 'field_data';
      $query->join($field_data_table, $field_data_alias, "$field_data_alias.{$this->idKey} = base.{$this->idKey}");

      // Join the revision data table in order to set the delete condition.
      $revision_data_table = $this->getRevisionDataTable();
      $revision_data_alias = 'revision_data';
      if ($revision_id) {
        $query->join($revision_data_table, $revision_data_alias, "$revision_data_alias.{$this->revisionKey} = revision.{$this->revisionKey} AND $revision_data_alias.{$this->revisionKey} = :revisionId", array(':revisionId' => $revision_id));
      }
      else {
        $query->join($revision_data_table, $revision_data_alias, "$revision_data_alias.{$this->revisionKey} = revision.{$this->revisionKey}");
      }
    }
    // Loading a revision is explicit. So when we try to load one we should do
    // so without a condition on the deleted flag.
    if (!$revision_id) {
      $query->condition("$revision_data_alias._deleted", (int) $this->isDeleted);
    }
    // Entities in other workspaces than the active one can only be queried with
    // the Entity Query API and not by the storage handler itself.
    // Just UserStorage can be queried in all workspaces by the storage handler.
    if (!$this instanceof UserStorageInterface) {
      // We have to join the data table to set a condition on the workspace.
      $query->condition("$field_data_alias.workspace", $this->getActiveWorkspaceId());
    }
    return $query;
  }

  /**
   * Helper method to get the active workspace ID.
   */
  protected function getActiveWorkspaceId() {
    return \Drupal::service('workspace.manager')->getActiveWorkspace()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function loadUnchanged($id) {
    $this->resetCache([$id]);
    return $this->load($id) ?: $this->loadDeleted($id);
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = NULL) {
    $this->isDeleted = FALSE;
    $this->currentWorkspace = TRUE;
    return parent::loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function loadDeleted($id) {
    $entities = $this->loadMultipleDeleted(array($id));
    return isset($entities[$id]) ? $entities[$id] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultipleDeleted(array $ids = NULL) {
    $this->isDeleted = TRUE;
    return parent::loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    // Every update is a new revision with this storage model.
    $entity->setNewRevision();

    // Index the revision.
    $branch = $this->buildRevisionBranch($entity);
    $this->indexEntityRevision($entity);
    $this->indexEntityRevisionTree($entity, $branch);

    // Prepare the file directory.
    if ($entity instanceof FileInterface) {
      $uri = $entity->getFileUri();
      $scheme = \Drupal::service('file_system')->uriScheme($uri) ?: 'public';
      $stream_wrapper_name = 'stream_wrapper.' . $scheme;
      multiversion_prepare_file_destination($uri, \Drupal::service($stream_wrapper_name));
    }

    try {
      $save_result = parent::save($entity);

      // Update indexes.
      $this->indexEntity($entity);
      $this->indexEntityRevision($entity);
      $this->trackConflicts($entity);

      return $save_result;
    }
    catch (\Exception $e) {
      // If a new attempt at saving the entity is made after an exception its
      // important that a new rev token is not generated.
      $entity->_rev->new_edit = FALSE;
      throw new EntityStorageException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Indexes basic information about the entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   */
  protected function indexEntity(EntityInterface $entity) {
    \Drupal::service('multiversion.entity_index.sequence')->add($entity);
    \Drupal::service('multiversion.entity_index.id')->add($entity);
    \Drupal::service('multiversion.entity_index.uuid')->add($entity);
  }

  /**
   * Indexes information about the revision.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param array $branch
   */
  protected function indexEntityRevision(EntityInterface $entity) {
    \Drupal::service('multiversion.entity_index.rev')->add($entity);
  }

  /**
   * Indexes the revision tree.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param array $branch
   */
  protected function indexEntityRevisionTree(EntityInterface $entity, $branch) {
    \Drupal::service('multiversion.entity_index.rev.tree')->updateTree($entity->uuid(), $branch);
  }

  /**
   * Builds the revision branch.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @return array
   */
  protected function buildRevisionBranch(EntityInterface $entity) {
    // We are going to index the revision ahead of save in order to accurately
    // determine if this is going to be the default revision or not. We also run
    // this logic here outside of any transactions that the parent storage
    // handler might perform. It's important that the revision index does not
    // get rolled back during exceptions. All records are kept in order to more
    // accurately build revision trees of all universally known revisions.
    $branch = [];
    $rev = $entity->_rev->value;
    list($i) = explode('-', $rev);

    // This is a regular local save operation and a new revision token should be
    // generated. The new_edit property will be set to FALSE during replication
    // to ensure the revision token is saved as-is.
    if ($entity->_rev->new_edit || $entity->_rev->is_stub) {
      // If this is the first revision it means that there's no parent.
      // By definition the existing revision value is the parent revision.
      $parent_rev = $i == 0 ? 0 : $rev;
      // Only generate a new revision if this is not a stub entity. This will
      // ensure that stub entities remain with the default value (0) to make it
      // clear on a storage level that this is a stub and not a "real" revision.
      if (!$entity->_rev->is_stub) {
        $rev = \Drupal::service('multiversion.manager')->newRevisionId(
          $entity, $i
        );
      }
      list(, $hash) = explode('-', $rev);
      $entity->_rev->value = $rev;
      $entity->_rev->revisions = [$hash];
      $branch[$rev] = [$parent_rev];

      // Add the parent revision to list of known revisions. This will be useful
      // if an exception is thrown during entity save and a new attempt is made.
      if ($parent_rev != 0) {
        list(, $parent_hash) = explode('-', $parent_rev);
        $entity->_rev->revisions = [$hash, $parent_hash];
      }
    }
    // A list of all known revisions can be passed in to let the current host
    // know about the revision history, for conflict handling etc. A list of
    // revisions are always passed in during replication.
    else {
      $revisions = $entity->_rev->revisions;
      for ($c = 0; $c < count($revisions); ++$c) {
        $p = $c + 1;
        $rev = $i-- . '-' . $revisions[$c];
        $parent_rev = isset($revisions[$p]) ? $i . '-' . $revisions[$p] : 0;
        $branch[$rev] = [$parent_rev];
      }
    }
    return $branch;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Revisit this logic with forward revisions in mind.
   */
  protected function doSave($id, EntityInterface $entity) {
    if ($entity->_rev->is_stub) {
      $entity->isDefaultRevision(TRUE);
    }
    else {
      // Enforce new revision if any module messed with it in a hook.
      $entity->setNewRevision();

      // Decide whether or not this is the default revision.
      if (!$entity->isNew()) {
        $default_rev = \Drupal::service('multiversion.entity_index.rev.tree')->getDefaultRevision($entity->uuid());
        if ($entity->_rev->value == $default_rev) {
          $entity->isDefaultRevision(TRUE);
        }
        // @todo: {@link https://www.drupal.org/node/2597538 Needs test.}
        else {
          $entity->isDefaultRevision(FALSE);
        }
      }
    }

    // Invalidate the cache tag.
    Cache::invalidateTags(['workspace_' . $this->entityTypeId . '_' . $id]);

    return parent::doSave($id, $entity);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $entities) {
    // Entities are always "deleted" as new revisions when using a Multiversion
    // storage handler.
    $ids = [];
    foreach ($entities as $entity) {
      $ids[] = $entity->id();
      $entity->_deleted->value = TRUE;
      $this->save($entity);
    }

    // Reset the static cache for the "deleted" entities.
    $this->resetCache(array_keys($ids));
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision($revision_id) {
    // Do nothing by design.
  }

  /**
   * {@inheritdoc}
   */
  public function purge(array $entities) {
    parent::delete($entities);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(array $ids = NULL) {
    parent::resetCache($ids);
    $ws = $this->getActiveWorkspaceId();
    if ($this->entityType->isStaticallyCacheable() && isset($ids)) {
      foreach ($ids as $id) {
        unset($this->entities[$ws][$id]);
      }
    }
    else {
      $this->entities[$ws] = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getFromStaticCache(array $ids) {
    $ws = $this->getActiveWorkspaceId();
    $entities = [];
    // Load any available entities from the internal cache.
    if ($this->entityType->isStaticallyCacheable() && !empty($this->entities[$ws])) {
      $entities += array_intersect_key($this->entities[$ws], array_flip($ids));
    }
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  protected function setStaticCache(array $entities) {
    if ($this->entityType->isStaticallyCacheable()) {
      $ws = $this->getActiveWorkspaceId();
      if (!isset($this->entities[$ws])) {
        $this->entities[$ws] = [];
      }
      $this->entities[$ws] += $entities;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCacheId($id) {
    $ws = $this->getActiveWorkspaceId();
    return "values:{$this->entityTypeId}:$id:$ws";
  }

  /**
   * {@inheritdoc}
   */
  protected function setPersistentCache($entities) {
    if (!$this->entityType->isPersistentlyCacheable()) {
      return;
    }
    $ws = $this->getActiveWorkspaceId();
    $cache_tags = [
      $this->entityTypeId . '_values',
      'entity_field_info',
      'workspace_' . $ws,
    ];
    foreach ($entities as $entity) {
      $id = $entity->id();
      $cache_tags[] = 'workspace_' . $this->entityTypeId . '_' . $id;
      $this->cacheBackend->set($this->buildCacheId($id), $entity, CacheBackendInterface::CACHE_PERMANENT, $cache_tags);
    }
  }

  /**
   * Uses the Conflict Tracker service to track conflicts for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to track for which to track conflicts.
   */
  protected function trackConflicts(EntityInterface $entity) {
    /** @var \Drupal\multiversion\Workspace\ConflictTrackerInterface $conflictTracker */
    $conflictTracker = \Drupal::service('workspace.conflict_tracker');
    /** @var \Drupal\multiversion\Entity\Index\RevisionTreeIndexInterface $tree */
    $tree = \Drupal::service('multiversion.entity_index.rev.tree');
    $conflicts = $tree->getConflicts($entity->uuid());
    if ($conflicts) {
      $conflictTracker->add($entity->uuid(), $conflicts, TRUE);
    }
    else {
      $conflictTracker->resolveAll($entity->uuid());
    }
  }

}
