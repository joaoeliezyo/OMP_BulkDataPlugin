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

		$zipFilename = $safeDoi . '.zip';
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $zipFilename;

		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

		// Metadados JSON (Completo v8)
		$metadata = $this->_getSubmissionMetadataAsArray($submission, $publication, $context->getId());
		$zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		// Arquivos
		$this->_addFilesToZip($zip, $submission, $publication, $context->getId());

		// HTML Snapshot Direto (Correção Docker Port)
		$htmlContent = $this->_getPublicPageHtml($request, $submission);
		if ($htmlContent) {
			$zip->addFromString('public_page_snapshot.html', $htmlContent);
		}

		$zip->close();
		return new JSONMessage(true, ['token' => $zipFilename]);
	}

	private function _getSubmissionMetadataAsArray($submission, $publication, $contextId) {
		$data = [
			'id' => $submission->getId(),
			'doi' => $publication->getData('pub-id::doi'),
			'title' => $publication->getLocalizedData('title'),
			'subtitle' => $publication->getLocalizedData('subtitle'),
			'abstract' => strip_tags($publication->getLocalizedData('abstract')),
			'language' => $publication->getData('locale'),
			'date_published' => $publication->getData('datePublished'),
			'authors' => $this->_getAuthorsMetadata($publication->getData('authors'), $contextId),
			'chapters' => []
		];

		$chapterDao = DAORegistry::getDAO('ChapterDAO');
		$chapters = $chapterDao->getByPublicationId($publication->getId());
		while ($chapter = $chapters->next()) {
			$data['chapters'][] = [
				'id' => $chapter->getId(),
				'doi' => $chapter->getStoredPubId('doi'),
				'title' => $chapter->getLocalizedTitle(),
				'subtitle' => $chapter->getLocalizedSubtitle(),
				'pages' => $chapter->getData('pages'),
				'abstract' => strip_tags($chapter->getLocalizedData('abstract')),
				'authors' => $this->_getAuthorsMetadata($chapter->getAuthors()->toArray(), $contextId)
			];
		}
		return $data;
	}

	private function _getAuthorsMetadata($authors, $contextId) {
		$authorsData = [];
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

		foreach ($authors as $author) {
			$userGroup = $userGroupDao->getById($author->getUserGroupId(), $contextId);
			$roleName = $userGroup ? $userGroup->getLocalizedName() : 'N/A';

			$authorsData[] = [
				'first_name' => $author->getLocalizedGivenName(),
				'last_name' => $author->getLocalizedFamilyName(),
				'full_name' => $author->getFullName(),
				'preferred_public_name' => $author->getLocalizedData('preferredPublicName'),
				'email' => $author->getEmail(),
				'country' => $author->getData('country'),
				'affiliation' => $author->getLocalizedData('affiliation'),
				'url' => $author->getData('url'),
				'orcid' => $author->getOrcid(),
				'biography' => strip_tags($author->getLocalizedData('biography')),
				'role_name' => $roleName,
				'is_primary_contact' => (bool) $author->getPrimaryContact(),
				'include_in_browse' => (bool) $author->getIncludeInBrowse(),
				'is_volume_editor' => (bool) $author->getData('isVolumeEditor')
			];
		}
		return $authorsData;
	}

	private function _addFilesToZip($zip, $submission, $publication, $contextId) {
		// Capa
		$coverImage = $publication->getLocalizedData('coverImage');
		if ($coverImage) {
			$coverName = $coverImage['uploadName'];
			$sourcePath = 'public/presses/' . $contextId . '/' . $coverName;
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
		$dispatcher = $request->getDispatcher();
		$url = $dispatcher->url($request, ROUTE_PAGE, null, 'catalog', 'book', array($submission->getId()));
		
		// Docker Fix: Se a URL contiver porta externa (8080), substituir por porta interna (80)
		$internalUrl = str_replace(':8080', '', $url);
		if (strpos($internalUrl, 'localhost') !== false && strpos($internalUrl, 'http://') === 0) {
			// Garantir que estamos chamando o loopback interno
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $internalUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BulkDataPlugin/1.0');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$html = curl_exec($ch);
		
		if (curl_errno($ch)) {
			error_log('BulkDataPlugin CURL Error on ' . $internalUrl . ': ' . curl_error($ch));
			$html = '<!-- Failed to capture: ' . curl_error($ch) . ' from URL ' . $internalUrl . ' -->';
		}
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
	public function getDescription() { return 'Plugin para exportação massiva v8 (Fidelidade Total de Metadados).'; }
}
