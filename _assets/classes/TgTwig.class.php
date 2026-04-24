<?php
class TgTwig extends \Twig\Environment {
    public string $trackController = '';
    public string $trackMethod     = '';

    public function render($name, array $context = []): string {
        if ($this->trackController !== '' && $this->trackMethod !== '') {
            PageVisitsModel::upsertVisit($this->trackController, $this->trackMethod);
        }
        return parent::render($name, $context);
    }
}
