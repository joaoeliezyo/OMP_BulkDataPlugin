{**
 * plugins/generic/BulkDataPlugin/templates/management.tpl
 *}
<style>
	.bulk-data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
	.bulk-data-table th, .bulk-data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
	.bulk-data-table th { background-color: #f5f5f5; }
	.progress-container { margin-top: 20px; display: none; padding: 15px; background: #f9f9f9; border: 1px solid #eee; }
	#progressBar { width: 100%; height: 20px; }
	.log-container { margin-top: 10px; max-height: 150px; overflow-y: auto; font-family: monospace; font-size: 11px; background: #fff; padding: 5px; border: 1px solid #ddd; }
	.status-pending { color: #888; }
	.status-working { color: #007ab2; font-weight: bold; }
	.status-done { color: #2d862d; }
	.status-error { color: #d9534f; }
</style>

<div id="bulkDataPluginHeader">
	<h3>Exportação Seletiva em Cascata</h3>
	<p class="description">Selecione as publicações que deseja baixar. O sistema processará uma por uma para garantir estabilidade.</p>
</div>

<div class="pkp_form_area">
	<div id="tableTools" style="margin-bottom: 10px;">
		<button class="pkp_button" id="btnSelectAll">Marcar Todos</button>
		<button class="pkp_button" id="btnDeselectAll">Desmarcar Todos</button>
		<input type="text" id="pluginSearch" placeholder="Buscar título ou DOI..." style="width: 250px; padding: 4px;">
	</div>

	<div style="max-height: 300px; overflow-y: auto;">
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
				<tr><td colspan="5">Carregando publicações...</td></tr>
			</tbody>
		</table>
	</div>

	<div class="progress-container" id="processContainer">
		<strong>Progresso da Execução: <span id="progressText">0/0</span></strong>
		<progress id="progressBar" value="0" max="100"></progress>
		<div id="log" class="log-container"></div>
		<div style="margin-top: 10px;">
			<button class="pkp_button pkp_button_destructive" id="btnCancel">Interromper Processo</button>
		</div>
	</div>

	<div class="pkp_form_section" style="margin-top: 20px;">
		<button class="pkp_button pkp_button_primary" type="button" id="startExport">Iniciar Download em Massa</button>
	</div>
</div>

<script type="text/javascript">
$(function() {
	var selectedIds = [];
	var isRunning = false;
	var currentIndex = 0;
	var $body = $('#submissionListBody');
	var baseUrl = '{url op="manage" category="generic" plugin=$pluginName}';

	// Carregar a lista inicial
	function loadList() {
		$.get(baseUrl + '&verb=list', function(res) {
			if (res.status) {
				$body.empty();
				res.content.forEach(function(item) {
					$body.append(
						'<tr data-id="'+item.id+'">' +
							'<td><input type="checkbox" class="sub-check" value="'+item.id+'"></td>' +
							'<td>'+item.id+'</td>' +
							'<td>'+item.doi+'</td>' +
							'<td class="title-cell">'+item.title+'</td>' +
							'<td class="status-cell status-pending">Pendente</td>' +
						'</tr>'
					);
				});
			}
		});
	}
	loadList();

	// Filtro de busca
	$('#pluginSearch').on('keyup', function() {
		var val = $(this).val().toLowerCase();
		$("#submissionListBody tr").filter(function() {
			$(this).toggle($(this).text().toLowerCase().indexOf(val) > -1)
		});
	});

	// Seleção
	$('#masterCheck, #btnSelectAll').click(function() {
		$('.sub-check:visible').prop('checked', true);
	});
	$('#btnDeselectAll').click(function() {
		$('.sub-check').prop('checked', false);
	});

	$('#startExport').click(function() {
		selectedIds = [];
		$('.sub-check:checked').each(function() {
			selectedIds.push($(this).val());
		});

		if (selectedIds.length === 0) {
			alert('Por favor, selecione ao menos um livro.');
			return;
		}

		if (!confirm('O sistema iniciará ' + selectedIds.length + ' downloads. Deseja continuar?')) return;

		isRunning = true;
		currentIndex = 0;
		$('#processContainer').show();
		$('#startExport').prop('disabled', true);
		$('#log').empty();
		updateProgress();
		processNext();
	});

	$('#btnCancel').click(function() {
		isRunning = false;
		addLog('Processo interrompido pelo usuário.');
		$('#startExport').prop('disabled', false);
	});

	function updateProgress() {
		var perc = (currentIndex / selectedIds.length) * 100;
		$('#progressBar').val(perc);
		$('#progressText').text(currentIndex + '/' + selectedIds.length);
	}

	function addLog(msg) {
		$('#log').prepend('<div>[' + new Date().toLocaleTimeString() + '] ' + msg + '</div>');
	}

	function processNext() {
		if (!isRunning || currentIndex >= selectedIds.length) {
			if (currentIndex >= selectedIds.length) {
				addLog('🏁 PROCESSO CONCLUÍDO COM SUCESSO!');
			}
			$('#startExport').prop('disabled', false);
			return;
		}

		var id = selectedIds[currentIndex];
		var $row = $('tr[data-id="'+id+'"]');
		var title = $row.find('.title-cell').text();

		$row.find('.status-cell').text('Processando...').attr('class', 'status-cell status-working');
		addLog('Preparando: ' + title);

		$.get(baseUrl + '&verb=prepare&id=' + id, function(res) {
			if (res.status) {
				addLog('Baixando: ' + title);
				$row.find('.status-cell').text('Concluído').attr('class', 'status-cell status-done');
				
				// Iniciar download
				window.location.href = baseUrl + '&verb=download&token=' + res.content.token;

				currentIndex++;
				updateProgress();
				// Pequeno atraso para dar tempo ao navegador de processar o download
				setTimeout(processNext, 1500);
			} else {
				addLog('❌ ERRO em ' + title + ': ' + res.content);
				$row.find('.status-cell').text('Erro').attr('class', 'status-cell status-error');
				currentIndex++;
				updateProgress();
				processNext();
			}
		}).fail(function() {
			addLog('❌ Falha na comunicação com o servidor.');
			isRunning = false;
		});
	}
});
</script>
