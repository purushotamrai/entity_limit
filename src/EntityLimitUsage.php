<?php

namespace Drupal\entity_limit;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Entity\EntityManagerInterface;

/**
 * Provide handler for all entity limit usage functions.
 */
class EntityLimitUsage {

  protected $entityManager;

  protected $violationStorage;

  protected $violationManager;

  protected $applicableLimits;

  /**
   * Construct entity_limit usage.
   *
   * @param EntityManagerInterface $entityManager
   *   Entity Manager.
   */
  public function __construct(EntityManagerInterface $entityManager, PluginManagerInterface $violationManager) {
    $this->entityManager = $entityManager;
    $this->violationStorage = $entityManager->getStorage('entity_limit');
    $this->violationManager = $violationManager;
  }

  /**
   * Check entity limit violations.
   */
  public function entityLimitViolationCheck($entityTypeId, $bundle = NULL) {
    $access = FALSE;
    $this->applicableLimits($entityTypeId, $bundle);

    if (!empty($this->applicableLimits)) {
      $this->priorityList();
      $access = $this->compareLimits($entityTypeId, $bundle);
    }
    return $access;
  }

  /**
   * Check access to current entity with applicable limits.
   *
   * @return bool
   *   Access for the given entity.
   */
  public function compareLimits($entityTypeId, $bundle) {
    // Compare limit from final applicable limits.
    foreach ($this->applicableLimits as $value) {
      $entityLimit = $value['entity'];
      $limit = $entityLimit->getLimit();
      $query = $entityLimit->getQuery($entityTypeId, $bundle);
      if (!empty($value['violation'])) {
        $value['violation']->addConditions($query);
      }
      $count = $query->count()->execute();
      if ($count >= $limit && $limit != ENTITYLIMIT_NO_LIMIT) {
        $access = TRUE;
        break;
      }
    }
    return $access;
  }

  /**
   * Get final limit configuration which will be checked for entity and bundle.
   */
  protected function priorityList() {
    foreach ($this->violationManager->getDefinitions() as $key => $definition) {
      $priorityList[$definition['priority']] = $key;
    }
    ksort($priorityList);

    // Get priority limit using plugins for current entity & bundle.
    foreach ($priorityList as $plugin_id) {
      if (isset($this->applicableLimits[$plugin_id])) {
        $this->applicableLimits = $this->applicableLimits[$plugin_id];
        break;
      }
    }

    // If no plugins are selected then  get limit with no_violation key.
    if (array_key_exists('no_violation', $this->applicableLimits)) {
      $this->applicableLimits = array_pop($this->applicableLimits);
    }
    return $this->applicableLimits;
  }

  /**
   * Get all applicable limits for the given entity type and bundle.
   */
  public function applicableLimits($entityTypeId, $bundle) {
    foreach ($this->enabledViolations() as $entity_limit_name => $entity_limit) {
      if ($entity_limit->isLimitApplicableToEntity($entityTypeId)) {
        if (empty($entity_limit->getBundles($entityTypeId)) || $entity_limit->isLimitApplicableToBundle($bundle)) {
          foreach ($entity_limit->violations() as $key => $violation) {
            if ($violation->processViolation() == ENTITYLIMIT_APPLY) {
              $this->applicableLimits[$key][$entity_limit_name]['violation'] = $violation;
              $this->applicableLimits[$key][$entity_limit_name]['entity'] = $entity_limit;
            }
            else {
              $this->applicableLimits['no_violation'][$entity_limit_name]['entity'] = $entity_limit;
            }
          }
        }
      }
    }
  }

  /**
   * Get all enabled entity limit violation plugins.
   *
   * @return array
   *   All enabled violations.
   */
  public function enabledViolations() {
    return $this->violationStorage->loadByProperties(['status' => TRUE]);
  }

}
