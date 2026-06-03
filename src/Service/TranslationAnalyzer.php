<?php

namespace Drupal\translation_completeness\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;

/**
 * Service to analyze multilingual translation dependencies and completeness.
 */
class TranslationAnalyzer {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a TranslationAnalyzer object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Calculates the translation completeness percentage of an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to analyze.
   * @param string $langcode
   *   The target language code to check against.
   * @param array $visited
   *   An internal array to prevent infinite recursion on circular references.
   *
   * @return array
   *   An array containing 'percentage', 'total_items', and 'translated_items'.
   */
  public function analyze(ContentEntityInterface $entity, string $langcode, array &$visited = []): array {
    $entity_type_id = $entity->getEntityTypeId();
    $entity_id = $entity->id();
    // Unique key to prevent infinite loops.
    $key = "$entity_type_id:$entity_id";

    if (isset($visited[$key])) {
      return ['percentage' => 100, 'total_items' => 0, 'translated_items' => 0];
    }
    $visited[$key] = TRUE;

    $total_items = 0;
    $translated_items = 0;

    // Check if the entity itself is translatable and translated.
    if ($entity->isTranslatable()) {
      $total_items++;
      if ($entity->hasTranslation($langcode)) {
        $translated_items++;
        // Switch to the translated entity for further field inspection.
        $entity = $entity->getTranslation($langcode);
      }
    }

    // Iterate through all fields to find references (e.g., paragraphs, taxonomy, media).
    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      $field_list = $entity->get($field_name);
      
      // If it's an entity reference field, we check its referenced entities.
      if ($field_list instanceof EntityReferenceFieldItemListInterface && !$field_list->isEmpty()) {
        // Some reference fields might not point to translatable content entities (e.g., config entities),
        // we only care about content entities for translation completeness.
        foreach ($field_list as $item) {
          if ($item->entity instanceof ContentEntityInterface) {
            $sub_result = $this->analyze($item->entity, $langcode, $visited);
            $total_items += $sub_result['total_items'];
            $translated_items += $sub_result['translated_items'];
          }
        }
      }
    }

    $percentage = $total_items > 0 ? round(($translated_items / $total_items) * 100, 2) : 100;

    return [
      'percentage' => $percentage,
      'total_items' => $total_items,
      'translated_items' => $translated_items,
    ];
  }

}
