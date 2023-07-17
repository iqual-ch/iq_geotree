<?php

namespace Drupal\iq_geotree\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile to import country taxonomy.
 *
 * Source of data: https://restcountries.com/v3.1/all.
 *
 * @todo abstract source of data.
 */
class ImportCountriesCommand extends DrushCommands {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The drupal logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerChannelFactory;

  /**
   * Constructs a new ProductImportCommand object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manger.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Logger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    LanguageManagerInterface $language_manager,
    LoggerChannelFactoryInterface $loggerChannelFactory
    ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->languageManager = $language_manager;
    $this->loggerChannelFactory = $loggerChannelFactory;
  }

  /**
   * Import all countries.
   *
   * @command iq-geotree:import-countries
   * @aliases iq-geotree-import-countries
   *
   * @usage iq-geotree:import-countries
   */
  public function importAll() {
    $json = file_get_contents('https://restcountries.com/v3.1/all');
    $data = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    $i = 0;
    foreach ($data as $country) {
      try {
        $data = [
          'vid' => 'iqgt_country',
          'name' => $country['name']['common'],
          'status' => 1,
          'uid' => 1,
          'field_iqgt_iso_code_2' => $country['cca2'],
          'field_iqgt_iso_code_3' => $country['cca3'],
          'field_iqgt_iso_numeric_code' => $country['ccn3'],
          'field_iqgt_continent' => $country['region'],
          'field_iqgt_subregion' => $country['subregion'],
          'field_iqgt_lat' => $country['latlng'][0],
          'field_iqgt_long' => $country['latlng'][1],
          'langcode' => ['value' => 'en'],
        ];
        $this->createOrUpdateTerm('iqgt_country', $country['name']['common'], $data);
        $i++;
      }
      catch (\Exception $e) {
        $this->loggerChannelFactory->get('iq_geotree')->notice($e->getMessage());
      }
    }
    $this->loggerChannelFactory->get('iq_geotree')->notice('Imported ' . $i . ' countries.');
    return 0;
  }

  /**
   * Creates or updates a country taxonomy term.
   *
   * @param string $vid
   *   The vocabulary id.
   * @param string $name
   *   The name of the country.
   * @param array $data
   *   An array of data to populate the country.
   */
  public function createOrUpdateTerm(string $vid, string $name, array $data) {
    $term = NULL;
    $query = $this->database->select('taxonomy_term_field_data', 't');
    $query
      ->condition('t.vid', $vid)
      ->condition('t.name', $name)
      ->fields('t', ['tid'])
      ->range(0, 1);
    $result = $query->execute();
    $tids = [];
    while ($row = $result->fetchAssoc()) {
      $tids[] = $row['tid'];
    }
    if (empty($tids)) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create($data);
      $term->enforceIsNew();
    }
    else {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tids[0]);
      if (!$term->hasTranslation('en')) {
        $term->addTranslation('en');
      }
      $translated_term = $term->getTranslation('en');
      $translated_term->set('name', $data['name']);
      $translated_term->set('field_iqgt_iso_code_2', $data['field_iqgt_iso_code_2']);
      $translated_term->set('field_iqgt_iso_code_3', $data['field_iqgt_iso_code_3']);
      $translated_term->set('field_iqgt_iso_numeric_code', $data['field_iqgt_iso_numeric_code']);
      $translated_term->set('field_iqgt_continent', $data['field_iqgt_continent']);
      $translated_term->set('field_iqgt_subregion', $data['field_iqgt_subregion']);
      $translated_term->set('field_iqgt_lat', $data['field_iqgt_lat']);
      $translated_term->set('field_iqgt_long', $data['field_iqgt_long']);
    }
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      if ($language->getId() != 'en') {
        if (!$term->hasTranslation($langcode)) {
          $term->addTranslation($langcode);
        }
        $translated_term = $term->getTranslation($langcode);
        $translated_term->set('name', \Locale::getDisplayRegion('-' . $data['field_iqgt_iso_code_2'], $langcode));
        $translated_term->save();
      }
    }
    $term->save();
  }

}
