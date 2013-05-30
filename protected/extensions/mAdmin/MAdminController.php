<?php

Yii::app()->getComponent('bootstrap');

class MAdminController extends CExtController
{
    public $layout = false;

    /**
     * @var string Name of managed model
     */
    public $modelName = '';

    /**
     * @var array Склонение должно соответствовать словам соответственно: (добавить .., редактирование .., список ..)
     */
    public $modelHumanTitle = array('модель', 'модели', 'моделей');

    /**
     * @var string|string[] Allowed actions. Array or comma separated string. Possible values: add,view,edit,delete
     */
    public $allowedActions = 'add,edit,delete';

    /**
     * @var string One of AdminController->allowedActions
     */
    public $defaultAction = 'list';

    /**
     * @var string
     */
    public $assetsUrl;

    public function init()
    {
        parent::init();

        /** @var $app CWebApplication */
        $app = Yii::app();
        $this->assetsUrl = $app->assetManager->publish(__DIR__ . '/assets');

        $this->viewPath = __DIR__ . '/views';
        /** @var $yiiTwigRenderer ETwigViewRenderer */
        $yiiTwigRenderer = Yii::app()->getComponent('viewRenderer');
        /** @var $twig_LoaderInterface Twig_Loader_Filesystem */
        $twig_LoaderInterface = $yiiTwigRenderer->getTwig()->getLoader();
        $twig_LoaderInterface->addPath($this->viewPath);
    }

    public function filters()
    {
        return array(
            'accessControl'
        );
    }

    private function getAllowedActions()
    {
        if (is_array($this->allowedActions)) {
            return $this->allowedActions;
        } else {
            $res = explode(',', $this->allowedActions);
            array_walk(
                $res,
                function (&$value) {
                    $value = trim($value);
                }
            );

            return $res;
        }
    }

    public function accessRules()
    {
        $allowedActions = array_merge($this->getAllowedActions(), array('index', 'list'));
        return array(
            array(
                'allow',
                'actions' => $allowedActions,
                'roles' => array('admin')
            ),
            array(
                'deny',
                'users' => array('*')
            ),
        );
    }

    public function actionIndex()
    {
        $this->render('index');
    }

    public function actionAdd()
    {
        $this->actionEdit(true);
    }

    /**
     * @param bool $createNew
     */
    public function actionEdit($createNew = false)
    {
        if ($createNew) {
            $model = new $this->modelName();
        } else {
            $model = $this->loadModel();
        }

        if (isset($_POST[$this->modelName])) {
            foreach ($_POST[$this->modelName] as &$postValue) {
                if (is_string($postValue)) {
                    $postValue = trim($postValue);
                    if ($postValue === '') {
                        $postValue = null;
                    }
                }
            }

            $modifiedRelations = $this->setAttributes($model);

            $this->beforeSave($model);
            if ($validated = $model->validate()) {
                foreach ($modifiedRelations as $relationName) {
                    $validated = $validated && $model->$relationName->validate();
                }
            }
            if ($validated) {
                $model->save(false);
                foreach ($modifiedRelations as $relationName) {
                    $model->$relationName->save(false);
                }

                $this->afterSave($model);
                $this->redirect(array('/' . $this->getUniqueId()));
            }
        }

        $this->beforeEdit($model);
        $this->render(
            'crud/' . ($createNew ? 'add' : 'edit'),
            array(
                'model' => $model,
                'editFormElements' => $this->getEditFormElements($model),
            )
        );
    }

    /**
     * @param CActiveRecord $model
     * @return string[] modified relations
     */
    private function setAttributes($model)
    {
        $modifiedRelations = array();
        $modelName = get_class($model);
        $this->beforeSetAttributes($model, $_POST[$modelName]);
        $model->setAttributes($_POST[$modelName]);
        foreach ($model->relations() as $relationName => $relationAttributes) {
            // process MANY_MANY relations
            if (isset($_POST[$modelName][$relationName])) {
                $model->$relationName = $_POST[$modelName][$relationName];
            }

            // process HAS_ONE or BELONGS_TO relations
            if (isset($_POST[$relationAttributes[1]])) {
                $modifiedRelations[] = $relationName;
                $modifiedRelations += $this->setAttributes($model->$relationName);
            }
        }

        return $modifiedRelations;
    }

    public function actionView()
    {
        $model = $this->loadModel();

        $this->render(
            'crud/view',
            array(
                'model' => $model,
                'editFormElements' => $this->getEditFormElements($model),
            )
        );
    }

    public function actionList()
    {
        /** @var $model CActiveRecord */
        $model = new $this->modelName('search');

        $this->beforeList($model, $_GET[$this->modelName]);
        if (isset($_GET[$this->modelName])) {
            $model->attributes = $_GET[$this->modelName];
        }

        $this->render(
            'crud/list',
            array(
                'model' => $model,
                'columns' => $this->getTableColumns(),
                'canAdd' => in_array('add', explode(',', $this->allowedActions)),
            )
        );
    }

    public function actionDelete()
    {
        if (Yii::app()->request->isPostRequest) {
            // we only allow deletion via POST request
            $this->loadModel()->delete();

            // if AJAX request (triggered by deletion via admin grid view), we should not redirect the browser
            if (!isset($_GET['ajax'])) {
                $this->redirect(array($this->getId()));
            }
        } else {
            throw new CHttpException(400);
        }
    }

    /**
     * internal
     * @return CActiveRecord
     * @throws CHttpException
     */
    public function loadModel()
    {
        $model = null;
        if (isset($_GET['id'])) {
            $model = CActiveRecord::model($this->modelName)->findbyPk($_GET['id']);
        }
        if ($model === null) {
            throw new CHttpException(404);
        } else {
            return $model;
        }
    }

    /**
     * Example:
     * <code>
     * return array(
     *     'attributeName1',
     *     array(
     *         'class' => 'alias.to.children.of.CGridColumn',
     *         'property1' => 'value',
     *         'property2' => 'value',
     *     ),
     *     array(
     *         'name' => 'attributeName2',
     *         'filter' => array(1 => 'Value 1', 2 => 'Value 2'),
     *         'sortable' => false,
     *         'value' => '$data->getHumanAttributeText()',
     *     ),
     * );
     * </code>
     * @see CGridView::$columns
     * @return array
     */
    public function getTableColumns()
    {
        $model = CActiveRecord::model($this->modelName);
        $attributes = $model->getAttributes();
        unset($attributes[$model->metaData->tableSchema->primaryKey]);
        $columns = array_keys($attributes);

        $columns[] = $this->getButtonsColumn();

        return $columns;
    }

    /**
     * internal
     * returns columns with buttons such as edit, view, delete
     * @return array
     */
    public function getButtonsColumn()
    {
        $allowedActions = explode(',', $this->allowedActions);
        $allowDelete = in_array('delete', $allowedActions);
        $allowView = in_array('view', $allowedActions);
        $allowEdit = in_array('edit', $allowedActions);

        $template = '';
        if (!$allowEdit && $allowView) {
            $template = '{view}';
        } elseif ($allowEdit) {
            $template = '{update}';
        }
        if ($allowDelete) {
            if (!empty($template)) {
                $template .= '&nbsp;&nbsp;&nbsp;{delete}';
            } else {
                $template = '{delete}';
            }
        }

        return array(
            'class' => 'bootstrap.widgets.TbButtonColumn',
            'template' => $template,
            'updateButtonUrl' => 'Yii::app()->controller->createUrl("edit",array("id"=>$data->primaryKey))'
        );
    }

    /**
     * Example:
     * <code>
     * return array(
     *      'attributeName1' => array(
     *          'type' => 'textField', // One of methods in TbActiveForm::*Row
     *      ),
     *      array(
     *          'class' => 'yii.class.alias', // i.e. application.extensions.DependedInputWidget
     *          'attribute1' => 'value1',
     *          'attribute2' => 'value2',
     *      ),
     *      '<h1>Raw html</h1>',
     *      'attributeName2' => array(
     *          'class' => 'yii.class.alias2',
     *      ),
     *      'attributeName3' => array(
     *          'type' => 'dropDownList', // Will be called TbActiveForm::DropDownListRow
     *          'data' => CHtml::listData(Client::model()->findAll(), 'id', 'name'),
     *          'htmlOptions' => array(
     *              'empty' => 'Empty',
     *          ),
     *      ),
     *      'relationName' => array(
     *          'rows' => array( same structure as root array ),
     *      ),
     *  );
     * </code>
     *
     * @param CActiveRecord $model
     * @return array
     */
    public function getEditFormElements($model)
    {
        return array();
    }

    /**
     * @param CActiveRecord $model
     * @param array $attributes
     */
    public function beforeSetAttributes($model, &$attributes)
    {
    }

    /**
     * @param CActiveRecord $model
     * @param array $attributes
     */
    public function beforeList($model, &$attributes)
    {
    }

    /**
     * @param CActiveRecord $model
     */
    public function beforeSave($model)
    {
    }

    /**
     * @param CActiveRecord $model
     */
    public function afterSave($model)
    {
    }

    /**
     * @param CActiveRecord $model
     */
    public function beforeEdit($model)
    {
    }
}