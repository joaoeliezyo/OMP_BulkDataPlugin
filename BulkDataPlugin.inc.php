<?php
import('lib.pkp.classes.plugins.GenericPlugin');

class BulkDataPlugin extends GenericPlugin {
	/**
	 * @palam string $category
	 * @param string $path
	 * @param int $mainContextId
	 */
	public function register($category, $path, $mainContextId = null) {
		if (parent::register($category, $path, $mainContextId)) {
			// Aqui registraremos os hooks futuramente
			return true;
		}
		return false;
	}

	/**
	 * @return string Nome exibido do plugin
	 */
	public function getDisplayName() {
		return 'Bulk Data Plugin';
	}

	/**
	 * @return string Descrição do plugin
	 */
	public function getDescription() {
		return 'Plugin para exportação massiva e submissão rápida de dados (CSV/ZIP) no OMP.';
	}
}
