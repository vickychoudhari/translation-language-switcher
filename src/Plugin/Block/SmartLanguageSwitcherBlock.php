<?php

namespace Drupal\translation_completeness\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\translation_completeness\Service\TranslationAnalyzer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Provides a 'Smart Language Switcher' block.
 *
 * @Block(
 *   id = "smart_language_switcher",
 *   admin_label = @Translation("Smart Language Switcher"),
 *   category = @Translation("Custom")
 * )
 */
class SmartLanguageSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The translation analyzer service.
   *
   * @var \Drupal\translation_completeness\Service\TranslationAnalyzer
   */
  protected $translationAnalyzer;

  /**
   * Constructs a SmartLanguageSwitcherBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\translation_completeness\Service\TranslationAnalyzer $translation_analyzer
   *   The translation analyzer service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LanguageManagerInterface $language_manager, RouteMatchInterface $route_match, TranslationAnalyzer $translation_analyzer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->languageManager = $language_manager;
    $this->routeMatch = $route_match;
    $this->translationAnalyzer = $translation_analyzer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('current_route_match'),
      $container->get('translation_completeness.analyzer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $languages = $this->languageManager->getLanguages();
    $current_language = $this->languageManager->getCurrentLanguage();
    $route_name = $this->routeMatch->getRouteName();
    $route_parameters = $this->routeMatch->getRawParameters()->all();
    
    // Attempt to find the main entity from the current route.
    $current_entity = NULL;
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof \Drupal\Core\Entity\ContentEntityInterface) {
        $current_entity = $parameter;
        break;
      }
    }

    $items = [];
    foreach ($languages as $language) {
      $langcode = $language->getId();
      $percentage_text = '';

      // If we are viewing an entity, analyze translation completeness.
      if ($current_entity) {
        $analysis = $this->translationAnalyzer->analyze($current_entity, $langcode);
        $percentage_text = ' (' . $analysis['percentage'] . '%)';
      }

      $url = Url::fromRoute($route_name, $route_parameters, [
        'language' => $language,
      ]);

      // Provide a fallback if the route cannot be generated for the given language.
      if (!$url->access()) {
        $url = Url::fromRoute('<front>', [], ['language' => $language]);
      }

      $link_text = $language->getName() . $percentage_text;
      
      $classes = ['language-link'];
      if ($langcode === $current_language->getId()) {
        $classes[] = 'is-active';
      }

      $items[] = [
        '#type' => 'link',
        '#title' => $link_text,
        '#url' => $url,
        '#attributes' => [
          'class' => $classes,
          'hreflang' => $langcode,
        ],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => $this->t('Languages'),
      '#attributes' => ['class' => ['smart-language-switcher']],
      '#cache' => [
        'contexts' => ['url.path', 'languages'],
        // Disable cache to always reflect current translation status.
        // For production, we should implement more granular cache tags based on the entity.
        'max-age' => 0, 
      ],
      '#attached' => [
        'library' => [
          'translation_completeness/smart_switcher',
        ],
      ],
    ];
  }

}
