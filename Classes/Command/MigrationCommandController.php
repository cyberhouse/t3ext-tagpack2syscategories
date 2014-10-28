<?php
namespace Cyberhouse\Tagpack2syscategories\Command;

use TYPO3\CMS\Extbase\Mvc\Controller\CommandController;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
class MigrationCommandController extends CommandController {

	const TAG_TABLE = 'tx_tagpack_tags';
	const TAG_MM_TABLE = 'tx_tagpack_tags_relations_mm';
	const SYS_TABLE = 'sys_category';
	const SYS_MM_TABLE = 'sys_category_record_mm';

	/**
	 * Runs the migration
	 *
	 * @return void
	 */
	public function runCommand() {
		$this->checkMigrationStatus();

		$this->migrateTags();
		$this->migrateTagRelations();
	}

	/**
	 * Dry run for the migration
	 *
	 * @return void
	 */
	public function checkCommand() {
		$this->checkMigrationStatus();
	}

	/**
	 * Migrate the tag records
	 *
	 * @return void
	 */
	protected function migrateTags() {
		$tagRows = $this->getDb()->exec_SELECTgetRows('*', self::TAG_TABLE, 'deleted=0');
		$countNew = $countUpdated = 0;

		foreach ($tagRows as $row) {
			$mapping = array(
				'pid' => $row['pid'],
				'tstamp' => $row['tstamp'],
				'crdate' => $row['crdate'],
				'cruser_id' => $row['cruser_id'],
				'sys_language_uid' => $row['sys_language_uid'],
				'l10n_parent' => $row['l18n_parent'],
				'hidden' => $row['hidden'],
				'starttime' => $row['starttime'],
				'endtime' => $row['endtime'],
				'title' => $row['name'],
				'description' => $row['description'],
			);

			$sysRecord = $this->getDb()->exec_SELECTgetSingleRow('*', self::SYS_TABLE, 'tx_tagpack_migration=' . $row['uid']);

			if (empty($sysRecord)) {
				$this->getDb()->exec_INSERTquery(self::SYS_TABLE, $mapping);
				usleep(2000);
				$newId = $this->getDb()->sql_insert_id();

				$updateSysCategory = array(
					'tx_tagpack_migration' => $row['uid']
				);
				$this->getDb()->exec_UPDATEquery(self::SYS_TABLE, 'uid=' . $newId, $updateSysCategory);
				$countNew++;
			} else {
				$this->getDb()->exec_UPDATEquery(self::SYS_TABLE, 'uid=' . $sysRecord['uid'], $mapping);
				$countUpdated++;
			}
		}

		$this->outputCharLine();
		$this->outputLine('Migrated tags: ' . $countNew);
		$this->outputLine('Updated tags: ' . $countUpdated);
		$this->outputCharLine();
	}

	/**
	 * Migrate the relations itself
	 *
	 * @return void
	 */
	protected function migrateTagRelations() {
		$uidMapping = $fieldMapping = array();
		$countIgnored = $countAlreadyDone = 0;
		$countInserts = array();

		$tagRelationRows = $this->getDb()->exec_SELECTgetRows('*', self::TAG_MM_TABLE, 'deleted=0');
		foreach ($tagRelationRows as $row) {
			$tableName = $row['tablenames'];

			if (!isset($countInserts[$tableName])) {
				$countInserts[$tableName] = 0;
			}
			if (!isset($fieldMapping[$tableName])) {
				$fieldMapping[$tableName] = $this->lookupFirstCategoryFieldInTca($tableName);
			}

			// If no table has been found, ignore those records
			if (empty($fieldMapping[$tableName])) {
				$countIgnored++;
				continue;
			}

			if (!isset($uidMapping[$row['uid_local']])) {
				$newCategoryRow = $this->getDb()->exec_SELECTgetSingleRow('*', self::SYS_TABLE, 'tx_tagpack_migration=' . $row['uid_local']);
				if (empty($newCategoryRow)) {
					$countIgnored++;
					continue;
				}
				$uidMapping[$row['uid_local']] = $newCategoryRow['uid'];
			} else {
				$countAlreadyDone++;
			}

			$mapping = array(
				'uid_local' => $uidMapping[$row['uid_local']],
				'uid_foreign' => $row['uid_foreign'],
				'tablenames' => $tableName,
				'fieldname' => $fieldMapping[$tableName],
				'sorting' => '',
				'sorting_foreign' => $row['sorting']
			);

			$where = sprintf(
				'uid_local="%s" AND uid_foreign="%s" AND tablenames="%s" AND fieldname="%s"',
				$mapping['uid_local'],
				$mapping['uid_foreign'],
				$mapping['tablenames'],
				$mapping['fieldname']
			);
			$relationExists = $this->getDb()->exec_SELECTcountRows('*', self::SYS_MM_TABLE, $where);
			if ($relationExists === 0) {
				$this->getDb()->exec_INSERTquery(self::SYS_MM_TABLE, $mapping);
				$countInserts[$tableName]++;
			}
		}

		$this->outputCharLine();
		foreach ($countInserts as $table => $tableCount) {
			$this->outputLine(sprintf('Migrated relations for table "%s": %s', $table, $tableCount));
		}

		$this->outputLine('Ignored relations: ' . $countIgnored);
		$this->outputLine('Already done relations: ' . $countAlreadyDone);
		$this->outputCharLine();
	}

	/**
	 * Check if the migration is needed
	 * and everything works
	 *
	 * @return void
	 */
	protected function checkMigrationStatus() {
		try {
			$allTables = $this->getDb()->admin_get_tables();
			if (!isset($allTables[self::TAG_TABLE])) {
				throw new \Exception(sprintf('Table "%s" not available, nothing to install', self::TAG_TABLE));
			}
			if (!isset($allTables[self::TAG_MM_TABLE])) {
				throw new \Exception(sprintf('Table "%s" not available, nothing to install', self::TAG_MM_TABLE));
			}

			$countTags = $this->getDb()->exec_SELECTcountRows('*', self::TAG_TABLE, 'deleted=0');
			if ($countTags === 0) {
				throw new \Exception(sprintf('Table "%s" is empty, nothing to be done', self::TAG_TABLE));
			}
			$countTagRelations = $this->getDb()->exec_SELECTcountRows('*', self::TAG_MM_TABLE, 'deleted=0');
			if ($countTagRelations === 0) {
				throw new \Exception(sprintf('Table "%s" is empty, nothing to be done', self::TAG_MM_TABLE));
			}

			$tablesUsingTags = $this->getDb()->exec_SELECTgetRows('tablenames', self::TAG_MM_TABLE, 'deleted=0', 'tablenames');
			foreach ($tablesUsingTags as $tableName) {
				$name = $tableName['tablenames'];

				$tcaField = $this->lookupFirstCategoryFieldInTca($name);
				if (!empty($tcaField)) {
					$this->outputLine(sprintf('INFO: The tag relation for the table "%s" will be saved in the field "%s"!', $name, $tcaField));
				} else {
					$this->outputLine(sprintf('ERROR: The tag relation for the table "%s" will NOT be saved! No relation to sys_category found in the TCA!', $name));
				}
			}
		} catch (\Exception $e) {
			$this->outputLine($e->getMessage());
			$this->sendAndExit(0);
		}
	}

	/**
	 * @param string $table
	 * @return string field of the category
	 */
	protected function lookupFirstCategoryFieldInTca($table) {
		$field = '';
		foreach ($GLOBALS['TCA'][$table]['columns'] as $fieldName => $configuration) {
			$configuration = $configuration['config'];
			if ($configuration['type'] === 'select' && $configuration['foreign_table'] === 'sys_category') {
				$field = $fieldName;
				continue;
			}
		}
		return $field;
	}

	/**
	 * Prints out a line full of given character
	 *
	 * @param string $char
	 * @return void
	 */
	protected function outputCharLine($char = '-') {
		$this->outputLine(str_repeat($char, CommandController::MAXIMUM_LINE_LENGTH));
	}

	/**
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected function getDb() {
		return $GLOBALS['TYPO3_DB'];
	}

}