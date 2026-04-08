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
			case 'import':
				return $this->_importZip($request);
		}
		return parent::manage($args, $request);
	}

	/**
	 * Módulo de Exportação (Mantido das versões anteriores)
	 * ... [Métodos list, allIds, prepare, download permanecem iguais] ...
	 */
	// [Abreviado para foco no import, mas será mantido no arquivo final]
	private function _listSubmissions($request) {
		$context = $request->getContext();
		$submissionService = Services::get('submission');
		$count = (int) ($request->getUserVar('count') ?: 10);
		$offset = (int) ($request->getUserVar('offset') ?: 0);
		$searchPhrase = $request->getUserVar('searchPhrase');
		$params = ['contextId' => $context->getId(), 'status' => STATUS_PUBLISHED, 'searchPhrase' => $searchPhrase];
		$totalSubmissions = $submissionService->getMany(array_merge($params, ['count' => 5000]))->count(); 
		$submissions = $submissionService->getMany(array_merge($params, ['count' => $count, 'offset' => $offset]));
		$data = [];
		foreach ($submissions as $submission) {
			$publication = $submission->getCurrentPublication();
			$data[] = ['id' => $submission->getId(), 'title' => $publication->getLocalizedData('title'), 'doi' => $publication->getData('pub-id::doi') ?: 'N/A'];
		}
		return new JSONMessage(true, ['items' => $data, 'total' => $totalSubmissions, 'page' => floor($offset / $count) + 1, 'totalPages' => ceil($totalSubmissions / $count)]);
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
		if (!$submission || $submission->getData('contextId') != $context->getId()) return new JSONMessage(false, 'Submissão inválida.');
		$publication = $submission->getCurrentPublication();
		$doi = $publication->getData('pub-id::doi') ?: 'submission_' . $submissionId;
		$safeDoi = preg_replace('/[^a-zA-Z0-9]/', '', $doi);
		$zipFilename = $safeDoi . '.zip';
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $zipFilename;
		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		$metadata = $this->_getSubmissionMetadataAsArray($submission, $publication, $context->getId());
		$zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		$this->_addFilesToZip($zip, $submission, $publication, $context->getId());
		$htmlContent = $this->_getPublicPageHtml($request, $submission);
		if ($htmlContent) $zip->addFromString('public_page_snapshot.html', $htmlContent);
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
			$roleName = $userGroup ? $userGroup->getLocalizedName() : 'Author';
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
		$coverImage = $publication->getLocalizedData('coverImage');
		if ($coverImage) {
			$coverName = $coverImage['uploadName'];
			$sourcePath = 'public/presses/' . $contextId . '/' . $coverName;
			if (file_exists($sourcePath)) $zip->addFile($sourcePath, 'covers/' . $coverName);
		}
		$files = Services::get('submissionFile')->getMany(['submissionIds' => [$submission->getId()], 'fileStages' => [SUBMISSION_FILE_PROOF, SUBMISSION_FILE_PRODUCTION_READY]]);
		foreach ($files as $file) {
			$sourcePath = Config::getVar('files', 'files_dir') . '/' . $file->getData('path');
			if (file_exists($sourcePath)) $zip->addFile($sourcePath, 'pdfs/' . basename($sourcePath));
		}
	}

	private function _getPublicPageHtml($request, $submission) {
		$dispatcher = $request->getDispatcher();
		$url = $dispatcher->url($request, ROUTE_PAGE, null, 'catalog', 'book', array($submission->getId()));
		$internalUrl = str_replace(':8080', '', $url);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $internalUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BulkDataPlugin/1.0');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$html = curl_exec($ch);
		if (curl_errno($ch)) $html = '<!-- Failed to capture -->';
		curl_close($ch);
		return $html;
	}

	private function _downloadZip($request) {
		$token = $request->getUserVar('token');
		$zipPath = Config::getVar('files', 'files_dir') . '/temp/' . $token;
		if (!file_exists($zipPath) || strpos($token, '..') !== false) fatalError('Arquivo expirado.');
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $token . '"');
		header('Content-Length: ' . filesize($zipPath));
		readfile($zipPath);
		unlink($zipPath);
		exit;
	}

	/**
	 * MÓDULO DE IMPORTAÇÃO (Novo na v9)
	 */
	private function _importZip($request) {
		$context = $request->getContext();
		$importMode = $request->getUserVar('importMode'); // 'submission' ou 'publication'
		$zipFile = $_FILES['zipFile'];

		if (!$zipFile || $zipFile['error'] !== UPLOAD_ERR_OK) {
			return new JSONMessage(false, 'Erro no upload do arquivo.');
		}

		$tempDir = Config::getVar('files', 'files_dir') . '/temp/import_' . uniqid();
		if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

		$zip = new ZipArchive();
		if ($zip->open($zipFile['tmp_name']) !== true) {
			return new JSONMessage(false, 'Não foi possível ler o arquivo ZIP.');
		}
		$zip->extractTo($tempDir);
		$zip->close();

		$jsonPath = $tempDir . '/metadata.json';
		if (!file_exists($jsonPath)) {
			return new JSONMessage(false, 'Arquivo metadata.json não encontrado no ZIP.');
		}

		$data = json_decode(file_get_contents($jsonPath), true);
		$logs = [];

		try {
			// 1. Criar Submissão
			$submissionService = Services::get('submission');
			$submission = $submissionService->newDataObject([
				'contextId' => $context->getId(),
				'locale' => $data['language'] ?: $context->getPrimaryLocale(),
				'status' => STATUS_QUEUED,
			]);
			$submission = $submissionService->add($submission, $request);
			$logs[] = ['type' => 'success', 'msg' => 'Submissão #'.$submission->getId().' criada.'];

			// 2. Criar Publicação
			$publicationService = Services::get('publication');
			$publication = $publicationService->newDataObject([
				'submissionId' => $submission->getId(),
				'title' => [$submission->getLocale() => $data['title']],
				'abstract' => [$submission->getLocale() => $data['abstract']],
				'prefix' => '',
				'subtitle' => [$submission->getLocale() => $data['subtitle']],
				'status' => STATUS_QUEUED,
				'pub-id::doi' => $data['doi'],
				'datePublished' => $data['date_published'],
			]);
			$publication = $publicationService->add($publication, $request);
			$logs[] = ['type' => 'success', 'msg' => 'Publicação configurada com metadados.'];

			// 3. Autores do Livro
			$this->_importAuthors($data['authors'], $publication, $context->getId(), $request, $logs);

			// 4. Capítulos
			$this->_importChapters($data['chapters'], $publication, $context->getId(), $request, $logs);

			// 5. Arquivos (PDF e Capa)
			$this->_importFiles($tempDir, $submission, $publication, $context->getId(), $request, $logs);

			// 6. Publicação Imediata se solicitado
			if ($importMode === 'publication') {
				$publicationService->publish($publication);
				$logs[] = ['type' => 'success', 'msg' => 'Obra publicada com sucesso no catálogo!'];
			}

			// Limpeza total do diretório temporário após sucesso
			$this->_recursiveRmdir($tempDir);

			return new JSONMessage(true, ['logs' => $logs]);

		} catch (Exception $e) {
			return new JSONMessage(false, 'Erro interno na importação: ' . $e->getMessage());
		}
	}

	private function _importFiles($tempDir, $submission, $publication, $contextId, $request, &$logs) {
		$submissionFileService = Services::get('submissionFile');
		
		// 5.1 Processar PDFs
		$pdfPaths = glob($tempDir . '/pdfs/*.pdf');
		if (!empty($pdfPaths)) {
			// Criar um Formato de Publicação (Representação) para os PDFs
			$publicationFormatDao = DAORegistry::getDAO('PublicationFormatDAO');
			$publicationFormat = $publicationFormatDao->newDataObject();
			$publicationFormat->setData('publicationId', $publication->getId());
			$publicationFormat->setName('Digital (Importado)', $publication->getData('locale'));
			$publicationFormat->setIsApproved(true);
			$publicationFormatDao->insertObject($publicationFormat);

			foreach ($pdfPaths as $pdfPath) {
				$fileName = basename($pdfPath);
				
				// Copiar para local temporário seguro
				$stagePath = Config::getVar('files', 'files_dir') . '/temp/' . $fileName;
				copy($pdfPath, $stagePath);

				$submissionFile = $submissionFileService->newDataObject([
					'submissionId' => $submission->getId(),
					'uploaderUserId' => $request->getUser()->getId(),
					'fileStage' => 17, // SUBMISSION_FILE_PROOF
					'assocType' => ASSOC_TYPE_REPRESENTATION,
					'assocId' => $publicationFormat->getId(),
				]);

				$submissionFile = $submissionFileService->add($submissionFile, $stagePath);
				$logs[] = ['type' => 'success', 'msg' => 'PDF Importado: ' . $fileName];
			}
		}

		// 5.2 Processar Capa
		$coverPaths = glob($tempDir . '/covers/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
		if (!empty($coverPaths)) {
			$coverPath = $coverPaths[0];
			$coverName = 'import_' . uniqid() . '_' . basename($coverPath);
			$destPath = 'public/presses/' . $contextId . '/' . $coverName;
			
			if (copy($coverPath, $destPath)) {
				$publicationService = Services::get('publication');
				$publicationService->edit($publication, [
					'coverImage' => [$publication->getData('locale') => [
						'uploadName' => $coverName,
						'dateUploaded' => date('Y-m-d H:i:s'),
						'altText' => ''
					]]
				], $request);
				$logs[] = ['type' => 'success', 'msg' => 'Capa importada e vinculada.'];
			}
		}
	}

	private function _recursiveRmdir($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object))
						$this->_recursiveRmdir($dir. DIRECTORY_SEPARATOR .$object);
					else
						unlink($dir. DIRECTORY_SEPARATOR .$object);
				}
			}
			rmdir($dir);
		}
	}

	private function _importAuthors($authorsData, $publication, $contextId, $request, &$logs) {
		$authorService = Services::get('author');
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		
		foreach ($authorsData as $aData) {
			// Mapear Role Name para User Group ID
			$userGroups = $userGroupDao->getByContextId($contextId);
			$userGroupId = null;
			while ($ug = $userGroups->next()) {
				if ($ug->getLocalizedName() == $aData['role_name']) {
					$userGroupId = $ug->getId();
					break;
				}
			}
			if (!$userGroupId) $userGroupId = 15; // Fallback para Autor

			$author = $authorService->newDataObject([
				'publicationId' => $publication->getId(),
				'email' => $aData['email'],
				'givenName' => [$publication->getData('locale') => $aData['first_name']],
				'familyName' => [$publication->getData('locale') => $aData['last_name']],
				'country' => $aData['country'],
				'orcid' => $aData['orcid'],
				'url' => $aData['url'],
				'biography' => [$publication->getData('locale') => $aData['biography']],
				'userGroupId' => $userGroupId,
				'includeInBrowse' => $aData['include_in_browse'],
				'isPrimaryContact' => $aData['is_primary_contact'],
			]);
			
			$authorService->add($author, $request);
			
			// Atualizar configuração isVolumeEditor se necessário
			if ($aData['is_volume_editor']) {
				$authorDao = DAORegistry::getDAO('AuthorDAO');
				$authorDao->updateSetting($author->getId(), 'isVolumeEditor', 1, 'int');
			}
		}
		$logs[] = ['type' => 'success', 'msg' => count($authorsData) . ' autores importados.'];
	}

	private function _importChapters($chaptersData, $publication, $contextId, $request, &$logs) {
		if (empty($chaptersData)) return;
		$chapterDao = DAORegistry::getDAO('ChapterDAO');
		foreach ($chaptersData as $cData) {
			$chapter = $chapterDao->newDataObject();
			$chapter->setData('publicationId', $publication->getId());
			$chapter->setTitle($cData['title'], $publication->getData('locale'));
			$chapter->setData('pages', $cData['pages']);
			$chapterDao->insertObject($chapter);
			
			if ($cData['doi']) {
				$chapterDao->updateSetting($chapter->getId(), 'pub-id::doi', $cData['doi']);
			}
			
			// Nota: Vinculação de autores aos capítulos requer lógica adicional de ID
		}
		$logs[] = ['type' => 'success', 'msg' => count($chaptersData) . ' capítulos criados.'];
	}

	public function getDisplayName() { return 'Bulk Data Plugin'; }
	public function getDescription() { return 'Plugin para exportação massiva e Importação Rápida (v9).'; }
}
