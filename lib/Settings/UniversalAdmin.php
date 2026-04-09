<?php

namespace OCA\SignotecSignoSignUniversal\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

final class UniversalAdmin implements ISettings {
	#[\Override]
	public function getForm() {
		// Load the admin settings JavaScript and CSS
		Util::addScript('signotecsignosignuniversal', 'signotecsignosignuniversal-adminSettings');
		Util::addStyle('signotecsignosignuniversal', 'signotecsignosignuniversal-adminSettings');

		return new TemplateResponse('signotecsignosignuniversal', 'adminsettings', [], '');
	}

	#[\Override]
	public function getSection() {
		return 'signotecsignosignuniversal';
	}

	#[\Override]
	public function getPriority() {
		return 10;
	}

	public function getName(): string {
		return 'Signotec Signo Sign Universal';
	}
}
