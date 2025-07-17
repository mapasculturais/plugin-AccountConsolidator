<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */


$agents = AccountConsolidator\Plugin::$instance->analyzePersonNames();

$this->jsObject['config']['account-consolidator--individual-to-collective'] = ['agents' => $agents];