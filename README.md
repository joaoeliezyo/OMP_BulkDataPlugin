# OMP Bulk Data Plugin

Plugin nativo para Open Monograph Press (OMP) 3.3.0-22 focado em exportação massiva e submissão rápida de dados.

## 🎯 Objetivo Global
Desenvolver um **"Generic Plugin"** único chamado `BulkDataPlugin` empacotado em um `.tar.gz` instalável via Interface do OMP. Este plugin oferecerá opções de "Exportação CSV" e "Submissão Rápida CSV".

---

## 🗺️ Fases do Desenvolvimento

### Fase 1: Forense e Entendimento de Estrutura
- Realizar uma submissão de teste e mapear vínculos no PostgreSQL.
- Mapear padrão de pastas em `omp_files`.

### Fase 2: O Módulo de Exportação
- Criar a interface administrativa.
- Desenvolver query SQL para exportar publicações ativas para CSV.

### Fase 3: O Módulo de Importação Rápida
- Criar upload de `.zip`.
- Desenvolver script PHP para inserção massiva de metadados e arquivos.

---

> [!IMPORTANT]
> ## Próximos Passos
> 1. Finalizar a submissão de teste no seu OMP local.
> 2. Informar o **Título** do livro enviado.
> 3. Deixaremos o mapeamento do banco de dados para a próxima etapa.
