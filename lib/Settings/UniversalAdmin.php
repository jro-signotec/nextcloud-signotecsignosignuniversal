<?php

namespace OCA\SignotecSignoSignUniversal\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class UniversalAdmin implements ISettings {
	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	public function getForm() {
		// Load the admin settings JavaScript
		Util::addScript('signotecsignosignuniversal', 'signotecsignosignuniversal-adminSettings');

		return new TemplateResponse('signotecsignosignuniversal', 'adminsettings', [], '');
	}

	public function getSection() {
		return 'signotecsignosignuniversal';
	}

	public function getPriority() {
		return 10;
	}

	public function getName() {
		return 'Signotec Signo Sign Universal';
	}
}
