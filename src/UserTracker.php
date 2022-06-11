<?php

namespace Drupal\user_tracker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds a field layout.
 */
class UserTracker implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new UserTracker.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(TranslationInterface $string_translation, RendererInterface $renderer, MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, ConfigFactoryInterface $config_factory, DateFormatterInterface $date_formatter) {
    $this->stringTranslation = $string_translation;
    $this->renderer = $renderer;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->configFactory = $config_factory;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('string_translation'),
      $container->get('renderer'),
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('config.factory'),
      $container->get('date.formatter')
    );
  }

  /**
   * Calculates changes and sends to mail.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $new_entity
   *   Entity that has been updated.
   */
  public function mailChanges(FieldableEntityInterface $new_entity) {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $old_entity */
    $old_entity = $new_entity->original;
    $diff = [];
    $fields = $new_entity->getFields();
    foreach ($fields as $new_field) {
      $field_name = $new_field->getName();
      $old_field = $old_entity->get($field_name);

      $max_count = max(count($old_field), count($new_field));
      for ($i = 0; $i < $max_count; $i++) {
        $old_value = $old_field[$i]?->getString();
        $new_value = $new_field[$i]?->getString();
        if ($new_value !== $old_value) {
          $this->formatValue($new_field, $i, $new_value);
          $this->formatValue($old_field, $i, $old_value);
          $diff[$field_name]['values'][] = [
            'old' => $old_value,
            'new' => $new_value,
          ];
        }
      }
      if (isset($diff[$field_name])) {
        $diff[$field_name]['label'] = $new_field->getFieldDefinition()
          ->getLabel();
      }
    }

    if ($diff) {
      /** @var \Drupal\user\UserInterface $user */
      $user = ($new_entity->getEntityTypeId() === 'profile')
        ? $new_entity->getOwner()
        : $new_entity;
      $mail = [
        '#theme' => 'user_tracker_mail',
        '#user_id' => $user->id(),
        '#username' => $user->label(),
        '#fields' => $diff,
      ];

      $to = $this->configFactory->get('system.site')->get('mail');
      $langcode = $this->languageManager->getCurrentLanguage()->getId();
      $params = [
        'subject' => $this->t('@username (@user_id) was changed', [
          '@username' => $user->label(),
          '@user_id' => $user->id(),
        ]),
        'body' => $this->renderer->renderPlain($mail),
      ];
      $this->mailManager->mail('user_tracker', 'changes', $to, $langcode, $params);
    }
  }

  /**
   * Formats a field value for human-readable.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field object.
   * @param int $index
   *   Value index.
   * @param mixed &$value
   *   The value to be formatted.
   */
  protected function formatValue(FieldItemListInterface $field, $index, &$value) {
    if (!$value) {
      return;
    }

    // You can customize the display in View mode.
    $view = $field[$index]->view('user_tracker');
    if ($view) {
      $value = $view;
    }
    else {
      // Custom formatting.
      $field_definition = $field->getFieldDefinition();
      switch ($field_definition->getType()) {
        case 'list_float':
        case 'list_integer':
        case 'list_string':
          $label = $field_definition->getSetting('allowed_values')[$value];
          $value = "$label ($value)";
          break;

        case 'entity_reference':
          /** @var \Drupal\Core\Entity\EntityInterface $entity */
          $entity = $field[$index]->entity;
          $value = "{$entity->label()} ({$entity->id()})";
          break;

        case 'created':
        case 'changed':
          $value = $this->dateFormatter->format($value, 'short');
          break;

        default:
          // You can implement a behavior to only display fields from view mode.
          break;
      }
    }
  }

}
