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
			return true;
		}
		return false;
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs) {
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$router = $request->getRouter();
		return array_merge(
			parent::getActions($request, $actionArgs),
			array(
				new LinkAction(
					'manage',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
						$this->getDisplayName()
					),
					'Gerenciar Bulk Data',
					null
				),
			)
		);
	}

	/**
	 * @copydoc GenericPlugin::manage()
	 */
	public function manage($args, $request) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->getName());

		switch ($request->getUserVar('verb')) {
			case 'settings':
				return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('management.tpl')));
			
			case 'export':
				return $this->_exportToZip($request);
		}
		return parent::manage($args, $request);
	}

	/**
	 * Realiza a exportação massiva para um arquivo ZIP
	 * @param Request $request
	 */
	private function _exportToZip($request) {
		$context = $request->getContext();
		$submissionService = Services::get('submission');
		
		$submissions = $submissionService->getMany([
			'contextId' => $context->getId(),
			'status' => STATUS_PUBLISHED,
		]);

		$tempDir = sys_get_temp_dir() . '/omp_bulk_' . uniqid();
		mkdir($tempDir);
		$filesDir = $tempDir . '/files';
		mkdir($filesDir);

		$csvData = [];
		$csvData[] = ['Submission ID', 'Publication ID', 'Title', 'Abstract', 'Authors', 'Cover File', 'PDF Files'];

		$zipFilename = 'omp_export_' . date('Ymd_His') . '.zip';
		$zipPath = sys_get_temp_dir() . '/' . $zipFilename;
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		foreach ($submissions as $submission) {
			$publication = $submission->getCurrentPublication();
			
			// Autores
			$authors = [];
			foreach ($publication->getData('authors') as $author) {
				$authors[] = $author->getFullName();
			}

			// Capa
			$coverFile = '';
			$coverImage = $publication->getData('coverImage', 'pt_BR');
			if ($coverImage) {
				$coverName = $coverImage['uploadName'];
				$sourcePath = Config::getVar('files', 'files_dir') . '/presses/' . $context->getId() . '/monographs/' . $submission->getId() . '/' . $coverName;
				if (file_exists($sourcePath)) {
					$zip->addFile($sourcePath, 'covers/' . $coverName);
					$coverFile = 'covers/' . $coverName;
				}
			}

			// PDFs de Prova / Produção
			$pdfFiles = [];
			$submissionFileService = Services::get('submissionFile');
			$files = $submissionFileService->getMany([
				'submissionIds' => [$submission->getId()],
				'fileStages' => [SUBMISSION_FILE_PROOF, SUBMISSION_FILE_PRODUCTION_READY],
			]);

			foreach ($files as $file) {
				$sourcePath = Config::getVar('files', 'files_dir') . '/' . $file->getData('path');
				if (file_exists($sourcePath)) {
					$destName = 'pdfs/' . $submission->getId() . '_' . $file->getData('fileId') . '.pdf';
					$zip->addFile($sourcePath, $destName);
					$pdfFiles[] = $destName;
				}
			}

			$csvData[] = [
				$submission->getId(),
				$publication->getId(),
				$publication->getLocalizedData('title'),
				strip_tags($publication->getLocalizedData('abstract')),
				implode('; ', $authors),
				$coverFile,
				implode('; ', $pdfFiles),
			];
		}

		// Adicionar CSV ao ZIP
		$csvHandle = fopen($tempDir . '/metadata.csv', 'w');
		foreach ($csvData as $row) {
			fputcsv($csvHandle, $row);
		}
		fclose($csvHandle);
		$zip->addFile($tempDir . '/metadata.csv', 'metadata.csv');

		$zip->close();

		// Download
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
		header('Content-Length: ' . filesize($zipPath));
		readfile($zipPath);

		// Cleanup
		unlink($zipPath);
		array_map('unlink', glob("$tempDir/*.*"));
		rmdir($tempDir);
		exit;
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
