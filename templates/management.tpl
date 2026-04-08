{**
 * plugins/generic/BulkDataPlugin/templates/management.tpl
 *}
<script type="text/javascript">
	$(function() {
		$('#bulkDataExportForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	});
</script>

<div id="bulkDataPluginHeader">
	<h3>Gerenciamento de Dados em Massa</h3>
	<p>Utilize as ferramentas abaixo para exportar ou importar publicações de forma massiva.</p>
</div>

{fbvFormArea id="exportArea" title="Exportação Completa (CSV + Arquivos)"}
	{fbvFormSection}
		<p class="description">Esta ferramenta irá gerar um arquivo <strong>.ZIP</strong> contendo:</p>
		<ul>
			<li>Um arquivo <strong>metadata.csv</strong> com todos os metadados das publicações.</li>
			<li>Pastas contendo os arquivos <strong>PDF</strong> e as <strong>Capas</strong> relacionadas.</li>
		</ul>
	{/fbvFormSection}
	
	<form class="pkp_form" id="bulkDataExportForm" method="post" action="{url op="manage" verb="export" category="generic" plugin=$pluginName}">
		{csrf}
		{fbvFormSection}
			{fbvButton type="submit" id="exportButton" label="Gerar e Baixar Pacote .ZIP de Exportação" variant=$fbvStyles.variant.PRIMARY}
		{/fbvFormSection}
	</form>
{/fbvFormArea}

<br />
<hr />
<br />

{fbvFormArea id="importArea" title="Submissão Rápida (Bulk Import)"}
	{fbvFormSection}
		<p class="description">Módulo de importação em desenvolvimento. Em breve você poderá subir um ZIP para submissão automática.</p>
		{fbvButton type="submit" id="importButton" label="Fazer Upload de Pacote (Breve)" disabled=true}
	{/fbvFormSection}
{/fbvFormArea}
