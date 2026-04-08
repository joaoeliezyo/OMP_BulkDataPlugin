<?php
interface_exists('PKPSubmission');
import('lib.pkp.classes.submission.PKPSubmission');
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
			case 'allIds':
				return $this->_getAllIds($request);
			case 'prepare':
				return $this->_prepareZip($request);
			case 'download':
				return $this->_downloadZip($request);
		}
		return parent::manage($args, $request);
	}

	private function _listSubmissions($request) {
		$context = $request->getContext();
		$submissionService = Services::get('submission');
		$count = (int) ($request->getUserVar('count') ?: 10);
		$offset = (int) ($request->getUserVar('offset') ?: 0);
		$searchPhrase = $request->getUserVar('searchPhrase');

		$params = [
			'contextId' => $context->getId(),
			'status' => STATUS_PUBLISHED,
			'searchPhrase' => $searchPhrase,
		];

		$totalSubmissions = $submissionService->getMany(array_merge($params, ['count' => 5000]))->count(); 
		$submissions = $submissionService->getMany(array_merge($params, ['count' => $count, 'offset' => $offset]));

		$data = [];
		foreach ($submissions as $submission) {
			$publication = $submission->getCurrentPublication();
			$data[] = [
				'id' => $submission->getId(),
				'title' => $publication->getLocalizedData('title'),
				'doi' => $publication->getData('pub-id::doi') ?: 'N/A',
			];
		}

		return new JSONMessage(true, [
			'items' => $data,
			'total' => $totalSubmissions,
			'page' => floor($offset / $count) + 1,
			'totalPages' => ceil($totalSubmissions / $count),
		]);
	}

	private function _getAllIds($request) {
		$context = $request->getContext();
		$searchPhrase = $request->getUserVar('searchPhrase');
		$params = ['contextId' => $context->getId(), 'status' => STATUS_PUBLISHED, 'searchPhrase' => $searchPhrase, 'count' => 5000];
		$submissionIds = Services::get('submission')->getIds($params);
		return new JSONMessage(true, $submissionIds);
	}

	private function _prepareZip($request) {
		$submissionId = (int) $request->getUserVar('id');
		$context = $request->getContext();
		$submission = Services::get('submission')->get($submissionId);
		if (!$submission || $submission->getData('contextId') != $context->getId()) {
			return new JSONMessage(false, 'Submissão inválida.');
		}

		$publication = $submission->getCurrentPublication();
		$doi = $publication->getData('pub-id::doi') ?: 'submission_' . $submissionId;
		$safeDoi = preg_replace('/[^a-zA-Z0-9]/', '', $doi);

		$tempDir = Config::getVar('files', 'files_dir') . '/temp/bulk_' . uniqid();
		if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

		$zipFilename = $safeDoi . '.zip';
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $zipFilename;

		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		// CSV de Metadados
		$csvPath = $tempDir . '/metadata.csv';
		$this->_generateMetadataCsv($csvPath, $submission, $publication);
		$zip->addFile($csvPath, 'metadata.csv');

		// Arquivos de Suporte
		$this->_addFilesToZip($zip, $submission, $publication, $context->getId());

		// HTML Snapshot
		$htmlContent = $this->_getPublicPageHtml($request, $submission);
		if ($htmlContent) {
			$zip->addFromString('pagina_publica_snapshot.html', $htmlContent);
		}

		$zip->close();
		unlink($csvPath);
		rmdir($tempDir);

		return new JSONMessage(true, ['token' => $zipFilename]);
	}

	private function _generateMetadataCsv($path, $submission, $publication) {
		$csvHandle = fopen($path, 'w');
		fputcsv($csvHandle, ['Entry Type', 'ID', 'DOI', 'Title/Heading', 'Authors', 'Pages', 'Abstract']);

		// Livro
		$authors = [];
		foreach ($publication->getData('authors') as $author) { $authors[] = $author->getFullName(); }
		fputcsv($csvHandle, [
			'BOOK',
			$submission->getId(),
			$publication->getData('pub-id::doi') ?: 'N/A',
			$publication->getLocalizedData('title'),
			implode('; ', $authors),
			'',
			strip_tags($publication->getLocalizedData('abstract')),
		]);

		// Capítulos (se existirem)
		$chapterDao = DAORegistry::getDAO('SubmissionChapterDAO');
		$chapters = $chapterDao->getByPublicationId($publication->getId());
		while ($chapter = $chapters->next()) {
			$chapterAuthors = [];
			$chapterAuthorsIter = $chapter->getAuthors();
			while ($cAuthor = $chapterAuthorsIter->next()) { $chapterAuthors[] = $cAuthor->getFullName(); }

			fputcsv($csvHandle, [
				'CHAPTER',
				$chapter->getId(),
				$chapter->getStoredPubId('doi') ?: 'N/A',
				$chapter->getLocalizedTitle(),
				implode('; ', $chapterAuthors),
				$chapter->getData('pages'),
				'',
			]);
		}
		fclose($csvHandle);
	}

	private function _addFilesToZip($zip, $submission, $publication, $contextId) {
		// Capa (Multi-idioma)
		$coverImage = $publication->getLocalizedData('coverImage');
		if ($coverImage) {
			$coverName = $coverImage['uploadName'];
			$sourcePath = Config::getVar('files', 'files_dir') . '/presses/' . $contextId . '/monographs/' . $submission->getId() . '/' . $coverName;
			if (file_exists($sourcePath)) {
				$zip->addFile($sourcePath, 'covers/' . $coverName);
			}
		}

		// PDFs
		$files = Services::get('submissionFile')->getMany(['submissionIds' => [$submission->getId()], 'fileStages' => [SUBMISSION_FILE_PROOF, SUBMISSION_FILE_PRODUCTION_READY]]);
		foreach ($files as $file) {
			$sourcePath = Config::getVar('files', 'files_dir') . '/' . $file->getData('path');
			if (file_exists($sourcePath)) {
				$zip->addFile($sourcePath, 'pdfs/' . basename($sourcePath));
			}
		}
	}

	private function _getPublicPageHtml($request, $submission) {
		$router = $request->getRouter();
		$url = $router->url($request, null, 'catalog', 'book', [$submission->getId()]);
		
		// Tentar buscar o HTML via curl (interno)
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		// Ignorar SSL se houver (para dev)
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		$html = curl_exec($ch);
		curl_close($ch);
		
		return $html;
	}

	private function _downloadZip($request) {
		$token = $request->getUserVar('token');
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $token;
		if (!file_exists($zipPath) || strpos($token, '..') !== false) {
			fatalError('Arquivo expirado ou inválido.');
		}
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $token . '"');
		header('Content-Length: ' . filesize($zipPath));
		readfile($zipPath);
		unlink($zipPath);
		exit;
	}

	public function getDisplayName() { return 'Bulk Data Plugin'; }
	public function getDescription() { return 'Plugin para exportação massiva v6 (DOI, Capítulos, HTML Snapshot).'; }
}
