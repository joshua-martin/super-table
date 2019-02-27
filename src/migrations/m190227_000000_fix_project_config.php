<?php
namespace verbb\supertable\migrations;

use verbb\supertable\SuperTable;
use verbb\supertable\fields\SuperTableField;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\fields\MatrixField;
use craft\fields\MissingField;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\MigrationHelper;
use craft\services\Fields;

class m190227_000000_fix_project_config extends Migration
{
    public function safeUp()
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $data = [];

        $superTableBlockTypes = (new Query())
            ->select([
                'bt.fieldId',
                'bt.fieldLayoutId',
                'bt.uid',
                'f.uid AS field',
            ])
            ->from(['{{%supertableblocktypes}} bt'])
            ->innerJoin('{{%fields}} f', '[[bt.fieldId]] = [[f.id]]')
            ->all();

        $layoutIds = [];
        $blockTypeData = [];

        foreach ($superTableBlockTypes as $superTableBlockType) {
            $fieldId = $superTableBlockType['fieldId'];
            unset($superTableBlockType['fieldId']);

            $layoutIds[] = $superTableBlockType['fieldLayoutId'];
            $blockTypeData[$fieldId][$superTableBlockType['uid']] = $superTableBlockType;
        }

        $superTableFieldLayouts = $this->_generateFieldLayoutArray($layoutIds);

        foreach ($blockTypeData as &$blockTypes) {
            foreach ($blockTypes as &$blockType) {
                $blockTypeUid = $blockType['uid'];
                $layout = $superTableFieldLayouts[$blockType['fieldLayoutId']];
                unset($blockType['uid'], $blockType['fieldLayoutId']);
                $blockType['fieldLayouts'] = [$layout['uid'] => ['tabs' => $layout['tabs']]];
                $data[$blockTypeUid] = $blockType;
            }
        }

        $fieldConfigs = $this->_getFieldData();

        foreach ($fieldConfigs as $fieldUid => $fieldConfig) {
            $context = ArrayHelper::remove($fieldConfig, 'context', 'global');

            if (strpos($context, 'superTableBlockType:') === 0) {
                $blockTypeUid = substr($context, 20);

                if (isset($data[$blockTypeUid])) {
                    $data[$blockTypeUid]['fields'][$fieldUid] = $fieldConfig;
                }
            }
        }

        $projectConfig->set('superTableBlockTypes', $data);
    }

    public function safeDown()
    {
        echo "m190227_000000_fix_project_config cannot be reverted.\n";
        return false;
    }

    private function _getFieldData(): array
    {
        $data = [];

        $fieldRows = (new Query())
            ->select([
                'fields.id',
                'fields.name',
                'fields.handle',
                'fields.context',
                'fields.instructions',
                'fields.searchable',
                'fields.translationMethod',
                'fields.translationKeyFormat',
                'fields.type',
                'fields.settings',
                'fields.uid',
                'fieldGroups.uid AS fieldGroup',
            ])
            ->from(['{{%fields}} fields'])
            ->leftJoin('{{%fieldgroups}} fieldGroups', '[[fields.groupId]] = [[fieldGroups.id]]')
            ->all();

        $fields = [];
        $fieldService = Craft::$app->getFields();

        // Massage the data and index by UID
        foreach ($fieldRows as $fieldRow) {
            $fieldRow['settings'] = Json::decodeIfJson($fieldRow['settings']);
            $fieldInstance = $fieldService->getFieldById($fieldRow['id']);
            $fieldRow['contentColumnType'] = $fieldInstance->getContentColumnType();
            $fields[$fieldRow['uid']] = $fieldRow;
        }

        foreach ($fields as $field) {
            $fieldUid = $field['uid'];
            unset($field['id'], $field['uid']);
            $data[$fieldUid] = $field;
        }

        return $data;
    }

    private function _generateFieldLayoutArray(array $layoutIds): array
    {
        // Get all the UIDs
        $fieldLayoutUids = (new Query())
            ->select(['id', 'uid'])
            ->from([Table::FIELDLAYOUTS])
            ->where(['id' => $layoutIds])
            ->pairs();

        $fieldLayouts = [];
        foreach ($fieldLayoutUids as $id => $uid) {
            $fieldLayouts[$id] = [
                'uid' => $uid,
                'tabs' => [],
            ];
        }

        // Get the tabs and fields
        $fieldRows = (new Query())
            ->select([
                'fields.handle',
                'fields.uid AS fieldUid',
                'layoutFields.fieldId',
                'layoutFields.required',
                'layoutFields.sortOrder AS fieldOrder',
                'tabs.id AS tabId',
                'tabs.name as tabName',
                'tabs.sortOrder AS tabOrder',
                'tabs.uid AS tabUid',
                'layouts.id AS layoutId',
            ])
            ->from(['{{%fieldlayoutfields}} AS layoutFields'])
            ->innerJoin('{{%fieldlayouttabs}} AS tabs', '[[layoutFields.tabId]] = [[tabs.id]]')
            ->innerJoin('{{%fieldlayouts}} AS layouts', '[[layoutFields.layoutId]] = [[layouts.id]]')
            ->innerJoin('{{%fields}} AS fields', '[[layoutFields.fieldId]] = [[fields.id]]')
            ->where(['layouts.id' => $layoutIds])
            ->orderBy(['tabs.sortOrder' => SORT_ASC, 'layoutFields.sortOrder' => SORT_ASC])
            ->all();

        foreach ($fieldRows as $fieldRow) {
            $layout = &$fieldLayouts[$fieldRow['layoutId']];

            if (empty($layout['tabs'][$fieldRow['tabUid']])) {
                $layout['tabs'][$fieldRow['tabUid']] =
                    [
                        'name' => $fieldRow['tabName'],
                        'sortOrder' => $fieldRow['tabOrder'],
                    ];
            }

            $tab = &$layout['tabs'][$fieldRow['tabUid']];

            $field['required'] = $fieldRow['required'];
            $field['sortOrder'] = $fieldRow['fieldOrder'];

            $tab['fields'][$fieldRow['fieldUid']] = $field;
        }

        // Get rid of UIDs
        foreach ($fieldLayouts as &$fieldLayout) {
            $fieldLayout['tabs'] = array_values($fieldLayout['tabs']);
        }

        return $fieldLayouts;
    }
}