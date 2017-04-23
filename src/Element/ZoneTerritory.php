<?php

namespace Drupal\address\Element;

use CommerceGuys\Addressing\AddressFormat\AddressFormat;
use CommerceGuys\Addressing\AddressFormat\AddressField;
use Drupal\address\FieldHelper;
use Drupal\address\LabelHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\FormElement;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;

/**
 * Provides a zone territory form element.
 *
 * Use it to populate a \CommerceGuys\Addressing\Zone\ZoneTerritory object.
 *
 * Usage example:
 * @code
 * $form['territory'] = [
 *   '#type' => 'zone_territory',
 *   '#default_value' => [
 *     'country_code' => 'US',
 *     'administrative_area' => 'CA',
 *     'included_postal_codes' => '94043',
 *   ],
 * ];
 * @endcode
 *
 * @FormElement("zone_territory")
 */
class ZoneTerritory extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#multiple' => FALSE,
      '#default_value' => NULL,
      '#process' => [
        [$class, 'processTerritory'],
        [$class, 'processGroup'],
      ],
      '#pre_render' => [
        [$class, 'preRenderGroup'],
      ],
      '#after_build' => [
        [$class, 'clearValues'],
      ],
      '#theme_wrappers' => ['container'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if (is_array($input)) {
      return $input;
    }
    else {
      if (!is_array($element['#default_value'])) {
        $element['#default_value'] = [];
      }
      // Initialize properties.
      $properties = [
        'country_code',
        'administrative_area', 'locality', 'dependent_locality',
        'included_postal_codes', 'excluded_postal_codes',
      ];
      foreach ($properties as $property) {
        if (!isset($element['#default_value'][$property])) {
          $element['#default_value'][$property] = NULL;
        }
      }

      return $element['#default_value'];
    }
  }

  /**
   * Processes the zone territory form element.
   *
   * @param array $element
   *   The form element to process.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The processed element.
   *
   * @throws \InvalidArgumentException
   *   Thrown when #available_countries or #used_fields is malformed.
   */
  public static function processTerritory(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $id_prefix = implode('-', $element['#parents']);
    $wrapper_id = Html::getUniqueId($id_prefix . '-ajax-wrapper');
    $country_list = \Drupal::service('address.country_repository')->getList();
    $value = $element['#value'];
    if (empty($value['country_code']) && $element['#required']) {
      // Fallback to the first country in the list if the default country
      // is empty even though the field is required.
      $value['country_code'] = key($country_list);
    }

    $element = [
      '#tree' => TRUE,
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
      // Pass the id along to other methods.
      '#wrapper_id' => $wrapper_id,
    ] + $element;
    $element['country_code'] = [
      '#type' => 'select',
      '#title' => t('Country'),
      '#options' => $country_list,
      '#default_value' => $value['country_code'],
      '#required' => $element['#required'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [get_called_class(), 'ajaxRefresh'],
        'wrapper' => $wrapper_id,
      ],
      '#weight' => -100,
    ];
    if (!$element['#required']) {
      $element['country_code']['#empty_value'] = '';
    }
    if (!empty($value['country_code'])) {
      /** @var \CommerceGuys\Addressing\AddressFormat\AddressFormat $address_format */
      $address_format = \Drupal::service('address.address_format_repository')->get($value['country_code']);
      $element = static::buildSubdivisionElements($element, $value, $address_format);
      $element = static::buildPostalCodeElements($element, $value, $address_format);
    }

    return $element;
  }

  /**
   * Builds the subdivision form elements.
   *
   * @param array $element
   *   The existing form element array.
   * @param array $value
   *   The element value.
   * @param \CommerceGuys\Addressing\AddressFormat\AddressFormat $address_format
   *   The address format for the selected country.
   *
   * @return array
   *   The form with the added subdivision elements.
   */
  protected static function buildSubdivisionElements(array $element, array $value, AddressFormat $address_format) {
    $depth = $address_format->getSubdivisionDepth();
    if ($depth === 0) {
      // No predefined data found.
      return $element;
    }

    $labels = LabelHelper::getFieldLabels($address_format);
    $subdivision_fields = $address_format->getUsedSubdivisionFields();
    $current_depth = 1;
    $parents = [];
    foreach ($subdivision_fields as $index => $field) {
      $property = FieldHelper::getPropertyName($field);
      $parent_property = $index ? FieldHelper::getPropertyName($subdivision_fields[$index - 1]) : 'country_code';
      if ($parent_property && empty($value[$parent_property])) {
        // No parent value selected.
        break;
      }
      $parents[] = $value[$parent_property];
      $subdivisions = \Drupal::service('address.subdivision_repository')->getList($parents);
      if (empty($subdivisions)) {
        break;
      }

      $element[$property] = [
        '#type' => 'select',
        '#title' => $labels[$field],
        '#options' => $subdivisions,
        '#default_value' => $value[$property],
        '#empty_option' => t('- All -'),
      ];
      if ($current_depth < $depth) {
        $element[$property]['#ajax'] = [
          'callback' => [get_called_class(), 'ajaxRefresh'],
          'wrapper' => $element['#wrapper_id'],
        ];
      }

      $current_depth++;
    }

    return $element;
  }

  /**
   * Builds the postal code form elements.
   *
   * @param array $element
   *   The existing form element array.
   * @param array $value
   *   The element value.
   * @param \CommerceGuys\Addressing\AddressFormat\AddressFormat $address_format
   *   The address format for the selected country.
   *
   * @return array
   *   The form with the added postal code elements.
   */
  protected static function buildPostalCodeElements(array $element, array $value, AddressFormat $address_format) {
    if (!in_array(AddressField::POSTAL_CODE, $address_format->getUsedFields())) {
      // The address format doesn't use a postal code field.
      return $element;
    }

    $element['included_postal_codes'] = [
      '#type' => 'textfield',
      '#title' => t('Included postal codes'),
      '#description' => t('A regular expression ("/(35|38)[0-9]{3}/") or comma-separated list, including ranges ("98, 100:200")'),
      '#default_value' => $value['included_postal_codes'],
    ];
    $element['excluded_postal_codes'] = [
      '#type' => 'textfield',
      '#title' => t('Excluded postal codes'),
      '#description' => t('A regular expression ("/(35|38)[0-9]{3}/") or comma-separated list, including ranges ("98, 100:200")'),
      '#default_value' => $value['excluded_postal_codes'],
    ];

    return $element;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxRefresh(array $form, FormStateInterface $form_state) {
    $country_element = $form_state->getTriggeringElement();
    $address_element = NestedArray::getValue($form, array_slice($country_element['#array_parents'], 0, -1));

    return $address_element;
  }

  /**
   * Clears the country-specific form values when the country changes.
   *
   * Implemented as an #after_build callback because #after_build runs before
   * validation, allowing the values to be cleared early enough to prevent the
   * "Illegal choice" error.
   */
  public static function clearValues(array $element, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    if (!$triggering_element) {
      return $element;
    }

    $triggering_element_name = end($triggering_element['#parents']);
    if ($triggering_element_name == 'country_code') {
      $keys = [
        'dependent_locality', 'locality', 'administrative_area',
      ];
      $input = &$form_state->getUserInput();
      foreach ($keys as $key) {
        $parents = array_merge($element['#parents'], [$key]);
        NestedArray::setValue($input, $parents, '');
        $element[$key]['#value'] = '';
      }
    }

    return $element;
  }

}
