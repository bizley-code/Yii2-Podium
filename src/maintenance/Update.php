<?php

namespace bizley\podium\maintenance;

use bizley\podium\components\Helper;
use bizley\podium\Podium;
use Exception;
use Yii;
use yii\helpers\Html;

/**
 * Podium Update
 * 
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.1
 * 
 * @property array $versionSteps
 * @property array $steps
 */
class Update extends Maintenance
{
    const SESSION_KEY = 'podium-update';
    const SESSION_STEPS = 'steps';
    const SESSION_VERSION = 'version';
    
    /**
     * @var array Update steps
     */
    private $_steps;
    
    /**
     * @var array Version steps
     */
    private $_versionSteps;
    
    /**
     * Proceeds next update step.
     * @return array
     * @since 0.2
     */
    public function nextStep()
    {
        $currentStep = Yii::$app->session->get(self::SESSION_KEY, 0);
        if ($currentStep === 0) {
            Yii::$app->session->set(self::SESSION_STEPS, count($this->versionSteps));
        }
        $maxStep = Yii::$app->session->get(self::SESSION_STEPS, 0);
        if ($currentStep < $maxStep) {
            $this->table = '...';
            if (!isset($this->versionSteps[$currentStep])) {
                return [
                    'type'    => self::TYPE_ERROR,
                    'result'  => Yii::t('podium/flash', 'Update aborted! Can not find the requested update step.'),
                    'percent' => 100,
                ];
            }
            $this->type = self::TYPE_SUCCESS;
            $this->table = $this->versionSteps[$currentStep]['table'];
            $result = call_user_func([$this, $this->versionSteps[$currentStep]['call']], $this->versionSteps[$currentStep]);
            Yii::$app->session->set(self::SESSION_KEY, ++$currentStep);
            return [
                'type'    => $this->type,
                'result'  => $result,
                'table'   => $this->getTable(true),
                'percent' => $this->countPercent($currentStep, $maxStep),
            ];
        }
        return [
            'type'    => self::TYPE_ERROR,
            'result'  => Yii::t('podium/flash', 'Weird... Update should already complete...'),
            'percent' => 100
        ];
    }
    
    /**
     * Returns update steps from next new version.
     * @return array
     * @since 0.2
     */
    public function getVersionSteps()
    {
        if ($this->_versionSteps === null) {
            $currentVersion = Yii::$app->session->get(self::SESSION_VERSION, 0);
            $versionSteps = [];
            foreach ($this->steps as $version => $steps) {
                if (Helper::compareVersions(explode('.', $currentVersion), explode('.', $version)) == '<') {
                    $versionSteps += $steps;
                }
            }
            $this->_versionSteps = $versionSteps;
        }
        return $this->_versionSteps;
    }
    
    /**
     * Updates database value in config table.
     * @param array $data
     * @return string result message.
     * @since 0.2
     */
    protected function updateValue($data)
    {
        if (!isset($data['name'])) {
            $this->type = self::TYPE_ERROR;
            return Yii::t('podium/flash', 'Installation aborted! Column name missing.');
        }
        if (!isset($data['value'])) {
            $this->type = self::TYPE_ERROR;
            return Yii::t('podium/flash', 'Installation aborted! Column value missing.');
        }
        
        try {
            Podium::getInstance()->config->set($data['name'], $data['value']);
            return Yii::t('podium/flash', 'Config setting {name} has been updated to {value}.', [
                'name'  => $data['name'],
                'value' => $data['value'],
            ]);
        } catch (Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
            $this->type = self::TYPE_ERROR;
            return Yii::t('podium/flash', 'Error during configuration updating') 
                . ': ' . Html::tag('pre', $e->getMessage());
        }
    }
    
    /**
     * Update steps.
     * @since 0.2
     */
    public function getSteps()
    {
        if ($this->_steps === null) {
            $this->_steps = require(__DIR__ . '/steps/update.php');
        }
        return $this->_steps;
    }
}
