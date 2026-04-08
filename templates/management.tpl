{**
 * plugins/generic/BulkDataPlugin/templates/management.tpl
 *}
<style>
	.bulk-data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
	.bulk-data-table th, .bulk-data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
	.bulk-data-table th { background-color: #f5f5f5; }
	.progress-container { margin-top: 20px; display: none; padding: 15px; background: #f9f9f9; border: 1px solid #eee; }
	#progressBar { width: 100%; height: 20px; }
	.log-container { margin-top: 10px; max-height: 150px; overflow-y: auto; font-family: monospace; font-size: 11px; background: #fff; padding: 5px; border: 1px solid #ddd; }
	.status-pending { color: #888; }
	.status-working { color: #007ab2; font-weight: bold; }
	.status-done { color: #2d862d; }
	.status-error { color: #d9534f; }
	.pagination-controls { margin-top: 15px; display: flex; align-items: center; justify-content: space-between; background: #f1f1f1; padding: 10px; border-radius: 4px; }
	.global-select-banner { display: none; background: #fff3cd; border: 1px solid #ffeeba; padding: 8px; margin-bottom: 10px; font-size: 13px; text-align: center; }
	.global-select-banner a { color: #856404; font-weight: bold; text-decoration: underline; cursor: pointer; }
</style>

<div id="bulkDataPluginHeader">
	<h3>Exportação Seletiva e Massiva (v5)</h3>
	<p class="description">Selecione as publicações. O sistema suporta paginação para editoras com grandes acervos.</p>
</div>

<div class="pkp_form_area">
	<div id="tableTools" style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
		<div>
			<input type="text" id="pluginSearch" placeholder="Buscar título ou DOI..." style="width: 200px; padding: 4px;">
			<select id="itemsPerPage" style="padding: 4px; margin-left: 10px;">
				<option value="10">10 por página</option>
				<option value="25">25 por página</option>
				<option value="50">50 por página</option>
				<option value="100">100 por página</option>
			</select>
		</div>
		<div style="font-size: 13px; font-weight: bold;">
			Total: <span id="totalItems">0</span> publicações | Página <span id="currentPageDisplay">1</span> de <span id="totalPagesDisplay">1</span>
		</div>
	</div>

	<div id="globalSelectBanner" class="global-select-banner">
		Todas as publicações desta página estão marcadas. <a id="btnSelectGlobal">Selecionar todas as <span class="total-count-text">...</span> publicações da editora?</a>
	</div>

	<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd;">
		<table class="bulk-data-table" id="submissionTable">
			<thead>
				<tr>
					<th width="30"><input type="checkbox" id="masterCheck"></th>
					<th width="50">ID</th>
					<th width="150">DOI</th>
					<th>Título</th>
					<th width="100">Status</th>
				</tr>
			</thead>
			<tbody id="submissionListBody">
				<tr><td colspan="5">Carregando...</td></tr>
			</tbody>
		</table>
	</div>

	<div class="pagination-controls">
		<button class="pkp_button" id="btnPrevPage">&larr; Anterior</button>
		<span>Página <span id="currentPageInd">1</span></span>
		<button class="pkp_button" id="btnNextPage">Próxima &rarr;</button>
	</div>

	<div class="progress-container" id="processContainer">
		<strong>Progresso do Lote: <span id="progressText">0/0</span></strong>
		<progress id="progressBar" value="0" max="100"></progress>
		<div id="log" class="log-container"></div>
		<div style="margin-top: 10px;">
			<button class="pkp_button pkp_button_destructive" id="btnCancel">Interromper Download</button>
		</div>
	</div>

	<div class="pkp_form_section" style="margin-top: 20px;">
		<button class="pkp_button pkp_button_primary" type="button" id="startExport">Iniciar Processamento dos Selecionados</button>
	</div>
</div>

<script type="text/javascript">
$(function() {
	var baseUrl = '{url op="manage" category="generic" plugin=$pluginName escape=false}';
	var currentOffset = 0;
	var currentCount = 10;
	var totalItems = 0;
	var totalPages = 1;
	var selectedIds = new Set();
	var isGlobalSelection = false;
	var isRunning = false;
	var queue = [];
	var currentIndex = 0;

	function loadPage() {
		var search = $('#pluginSearch').val();
		$('#submissionListBody').html('<tr><td colspan="5">Carregando...</td></tr>');
		
		$.get(baseUrl + '&verb=list', {
			offset: currentOffset,
			count: currentCount,
			searchPhrase: search
		}, function(res) {
			if (res.status) {
				var data = res.content;
				totalItems = data.total;
				totalPages = data.totalPages;
				
				$('#totalItems, .total-count-text').text(totalItems);
				$('#currentPageDisplay, #currentPageInd').text(data.page);
				$('#totalPagesDisplay').text(totalPages);
				
				var $body = $('#submissionListBody').empty();
				data.items.forEach(function(item) {
					var checked = (isGlobalSelection || selectedIds.has(String(item.id))) ? 'checked' : '';
					$body.append(
						'<tr data-id="'+item.id+'">' +
							'<td><input type="checkbox" class="sub-check" value="'+item.id+'" '+checked+'></td>' +
							'<td>'+item.id+'</td>' +
							'<td>'+item.doi+'</td>' +
							'<td class="title-cell">'+item.title+'</td>' +
							'<td class="status-cell status-pending">Pendente</td>' +
						'</tr>'
					);
				});
				updateBanner();
			}
		});
	}
	loadPage();

	// Handlers de Página
	$('#btnNextPage').click(function() {
		if ((currentOffset + currentCount) < totalItems) {
			currentOffset += currentCount;
			loadPage();
		}
	});
	$('#btnPrevPage').click(function() {
		if (currentOffset > 0) {
			currentOffset -= currentCount;
			loadPage();
		}
	});
	$('#itemsPerPage').change(function() {
		currentCount = parseInt($(this).val());
		currentOffset = 0;
		loadPage();
	});
	$('#pluginSearch').on('keyup', function() {
		clearTimeout(window.searchTimer);
		window.searchTimer = setTimeout(function() {
			currentOffset = 0;
			loadPage();
		}, 500);
	});

	// Checkbox Logic
	$(document).on('change', '.sub-check', function() {
		var id = $(this).val();
		if ($(this).is(':checked')) {
			selectedIds.add(id);
		} else {
			selectedIds.delete(id);
			isGlobalSelection = false;
		}
		updateBanner();
	});

	$('#masterCheck').change(function() {
		var isChecked = $(this).is(':checked');
		$('.sub-check').prop('checked', isChecked).trigger('change');
		if (!isChecked) isGlobalSelection = false;
	});

	function updateBanner() {
		var allCheckedInPage = $('.sub-check:checked').length === $('.sub-check').length && $('.sub-check').length > 0;
		if (allCheckedInPage && !isGlobalSelection && totalItems > $('.sub-check').length) {
			$('#globalSelectBanner').show();
		} else if (isGlobalSelection) {
			$('#globalSelectBanner').html('<strong>Todas as '+totalItems+' publicações da editora estão selecionadas.</strong> <a id="btnClearGlobal">Limpar seleção?</a>').show();
		} else {
			$('#globalSelectBanner').hide();
		}
	}

	$(document).on('click', '#btnSelectGlobal', function() {
		isGlobalSelection = true;
		updateBanner();
	});

	$(document).on('click', '#btnClearGlobal', function() {
		isGlobalSelection = false;
		selectedIds.clear();
		$('.sub-check, #masterCheck').prop('checked', false);
		updateBanner();
	});

	// Export ORCHESTRATOR
	$('#startExport').click(function() {
		if (isRunning) return;

		if (isGlobalSelection) {
			// Buscar lista completa de IDs
			addLog('Iniciando seleção GLOBAL. Buscando IDs de todas as publicações...');
			$.get(baseUrl + '&verb=allIds', { searchPhrase: $('#pluginSearch').val() }, function(res) {
				if (res.status) {
					queue = res.content;
					startWaterfall();
				}
			});
		} else {
			queue = Array.from(selectedIds);
			if (queue.length === 0) {
				alert('Selecione ao menos uma publicação.');
				return;
			}
			startWaterfall();
		}
	});

	function startWaterfall() {
		isRunning = true;
		currentIndex = 0;
		$('#processContainer').show();
		$('#startExport').prop('disabled', true);
		$('#log').empty();
		addLog('🚀 Iniciando download em massa de ' + queue.length + ' itens...');
		processNext();
	}

	function processNext() {
		if (!isRunning || currentIndex >= queue.length) {
			if (currentIndex >= queue.length) {
				addLog('🏁 PROCESSO CONCLUÍDO!');
			}
			$('#startExport').prop('disabled', false);
			isRunning = false;
			return;
		}

		var id = queue[currentIndex];
		$('#progressText').text((currentIndex + 1) + ' / ' + queue.length);
		$('#progressBar').val(((currentIndex + 1) / queue.length) * 100);

		$.get(baseUrl + '&verb=prepare&id=' + id, function(res) {
			if (res.status) {
				addLog('OK [' + id + ']: Arquivo preparado.');
				window.location.href = baseUrl + '&verb=download&token=' + res.content.token;
				currentIndex++;
				setTimeout(processNext, 1200);
			} else {
				addLog('❌ ERRO [' + id + ']: ' + res.content);
				currentIndex++;
				processNext();
			}
		}).fail(function() {
			addLog('❌ Falha crítica no servidor ao processar ID ' + id);
			isRunning = false;
			$('#startExport').prop('disabled', false);
		});
	}

	function addLog(msg) {
		$('#log').prepend('<div>[' + new Date().toLocaleTimeString() + '] ' + msg + '</div>');
	}

	$('#btnCancel').click(function() {
		isRunning = false;
		addLog('⏳ Interrompendo após a conclusão do item atual...');
	});
});
</script>
