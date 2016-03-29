<?php

/**
 * @file
 * Contains \Drupal\multiversion\Entity\Storage\Sql\TermStorageSchema.
 */

namespace Drupal\multiversion\Entity\Storage\Sql;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\entity_storage_migrate\Entity\Storage\ContentEntityStorageSchemaTrait;
use Drupal\taxonomy\TermStorageSchema as CoreTermStorageSchema;

/**
 * Storage schema handler for taxonomy terms.
 */
class TermStorageSchema extends CoreTermStorageSchema {

  use ContentEntityStorageSchemaTrait;

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);

    // @todo: Optimize indexes with the workspace field.
    return $schema;
  }

}
