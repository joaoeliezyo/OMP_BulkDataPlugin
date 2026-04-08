<?php
import('lib.pkp.classes.plugins.GenericPlugin');

class BulkDataPlugin extends GenericPlugin {
	public function register($category, $path, $mainContextId = null) {
		if (parent::register($category, $path, $mainContextId)) {
			return true;
		}
		return false;
	}

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

	public function manage($args, $request) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->getName());

		switch ($request->getUserVar('verb')) {
			case 'settings':
				return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('management.tpl')));
			
			case 'list':
				return $this->_listSubmissions($request);

			case 'prepare':
				return $this->_prepareZip($request);

			case 'download':
				return $this->_downloadZip($request);
		}
		return parent::manage($args, $request);
	}

	/**
	 * Lista submissões publicadas com ID, Título e DOI
	 */
	private function _listSubmissions($request) {
		$context = $request->getContext();
		$submissionService = Services::get('submission');
		
		$submissions = $submissionService->getMany([
			'contextId' => $context->getId(),
			'status' => STATUS_PUBLISHED, // Corrigido para a constante carregada pelo serviço
		]);

		$data = [];
		foreach ($submissions as $submission) {
			$publication = $submission->getCurrentPublication();
			$data[] = [
				'id' => $submission->getId(),
				'title' => $publication->getLocalizedData('title'),
				'doi' => $publication->getData('pub-id::doi') ?: 'N/A',
			];
		}

		return new JSONMessage(true, $data);
	}

	/**
	 * Prepara um ZIP individual para uma submissão
	 */
	private function _prepareZip($request) {
		$submissionId = (int) $request->getUserVar('id');
		$context = $request->getContext();
		
		$submission = Services::get('submission')->get($submissionId);
		if (!$submission || $submission->getData('contextId') != $context->getId()) {
			return new JSONMessage(false, 'Submissão inválida.');
		}

		$publication = $submission->getCurrentPublication();
		$tempDir = Config::getVar('files', 'files_dir') . '/temp/bulk_' . uniqid();
		if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

		$zipFilename = 'submission_' . $submissionId . '_' . uniqid() . '.zip';
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $zipFilename;

		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE);

		// CSV Individual
		$csvPath = $tempDir . '/metadata.csv';
		$csvHandle = fopen($csvPath, 'w');
		fputcsv($csvHandle, ['ID', 'Title', 'DOI', 'Authors', 'Abstract']);
		
		$authors = [];
		foreach ($publication->getData('authors') as $author) {
			$authors[] = $author->getFullName();
		}

		fputcsv($csvHandle, [
			$submission->getId(),
			$publication->getLocalizedData('title'),
			$publication->getData('pub-id::doi') ?: 'N/A',
			implode('; ', $authors),
			strip_tags($publication->getLocalizedData('abstract')),
		]);
		fclose($csvHandle);
		$zip->addFile($csvPath, 'metadata.csv');

		// Arquivos (PDF e Capa)
		$this->_addFilesToZip($zip, $submission, $publication, $context->getId());

		$zip->close();
		
		// Cleanup CSV temp
		unlink($csvPath);
		rmdir($tempDir);

		return new JSONMessage(true, ['token' => $zipFilename]);
	}

	private function _addFilesToZip($zip, $submission, $publication, $contextId) {
		// Capa
		$coverImage = $publication->getData('coverImage', 'pt_BR');
		if ($coverImage) {
			$coverName = $coverImage['uploadName'];
			$sourcePath = Config::getVar('files', 'files_dir') . '/presses/' . $contextId . '/monographs/' . $submission->getId() . '/' . $coverName;
			if (file_exists($sourcePath)) {
				$zip->addFile($sourcePath, 'covers/' . $coverName);
			}
		}

		// PDFs
		$files = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
			'fileStages' => [SUBMISSION_FILE_PROOF, SUBMISSION_FILE_PRODUCTION_READY],
		]);

		foreach ($files as $file) {
			$sourcePath = Config::getVar('files', 'files_dir') . '/' . $file->getData('path');
			if (file_exists($sourcePath)) {
				$zip->addFile($sourcePath, 'pdfs/' . basename($sourcePath));
			}
		}
	}

	/**
	 * Serve o arquivo e o deleta
	 */
	private function _downloadZip($request) {
		$token = $request->getUserVar('token');
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $token;

		if (!file_exists($zipPath) || strpos($token, '..') !== false) {
			fatalError('Arquivo expirado ou inválido.');
		}

		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="export_' . $token . '"');
		header('Content-Length: ' . filesize($zipPath));
		
		readfile($zipPath);
		unlink($zipPath);
		exit;
	}

	public function getDisplayName() {
		return 'Bulk Data Plugin';
	}

	public function getDescription() {
		return 'Plugin para exportação massiva em cascata e submissão rápida no OMP.';
	}
}
