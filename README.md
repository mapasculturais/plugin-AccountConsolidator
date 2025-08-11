
# AccountConsolidator  

Plugin para consolidação e organização de contas de usuários  

## Funcionalidades  

### 1. Conversão de Agentes Individuais para Coletivos  
- Identifica agentes coletivos cadastrados como individuais com base em palavras-chave  
- Exibe lista para confirmação do usuário (com opção de desmarcar falsos positivos)  
- Altera automaticamente o tipo dos agentes confirmados para "coletivo"  

### 2. Correção de Agentes de Perfil  
Estratégia de correção hierárquica:  

**Para usuários com agentes individuais:**  
1. Único agente individual → define como perfil  
2. Múltiplos agentes individuais → seleção por ordem de prioridade:  
   - Email igual ao usuário + CPF preenchido  
   - CPF preenchido  
   - Email igual ao usuário  
   - Primeiro da lista  

**Para usuários sem agentes individuais:**  
1. Agente coletivo com CPF (sem CNPJ) + email igual  
2. Agente coletivo com CPF (sem CNPJ)  
3. Agente coletivo sem documentos  
4. Cria novo agente se nenhum for encontrado  

### 3. Mesclagem de Agentes Duplicados  
- Identifica e consolida agentes com mesmo CPF  
- Critérios de mesclagem:  
  - Prioriza agente de perfil do usuário com login mais recente  
  - Mantém informações mais atualizadas de cada metadado  
  - Consolida galerias (fotos/vídeos) evitando duplicatas  
  - Preserva todas as vinculações (eventos, projetos, etc.)  

### 4. Normalização de Subagentes Individuais  
- Garante 1 agente individual por usuário  
- Para subagentes:  
  - Cria novos usuários quando possível  
  - Gera solicitação de controle para o usuário original  
  - Envia e-mail de notificação com instruções  

## Configuração  

- **Metadados de documentos**  
  - Pessoa física: `document_metadata_key = 'cpf'`  
  - Pessoa jurídica: `pj_document_metadata_key = 'cnpj'`  

- **Parâmetros de comparação**  
  - Similaridade mínima: `similarity_cutoff = 67` (67%)  
  - Emails ignorados: `skip_user_emails = []`  
  - Termos removidos: `skip_terms = ['(in memoriam)']`  
  - Termos obrigatórios compartilhados: `required_common_terms = ['mei']`  

- **Classificação de agentes**  
  - Termos indicativos de PF: `person_terms = ['do coco']`  
  - Termos indicativos de PJ: `colective_terms = include __DIR__ . '/collective-terms.php'`  

- **Suporte**  
  - Contato para suporte: `supportContact = ''`  

## Como Usar  
1. Acesse: `https://seu.dominio/account-consolidator`  
2. Revise e ajuste a lista de agentes identificados  
3. Clique em **[Iniciar consolidação das contas]**  

⚠️ **Atenção:** Processos de mesclagem podem levar horas para conclusão.  

## Logs e Resultados  
Relatórios detalhados são gerados em `/var/www/var/logs/AccountConsolidator`:  

- Arquivos individuais para cada operação  
- Resumo consolidado em `summary.log` (exemplo):  

```
=========================================
CONVERSÃO PARA AGENTES COLETIVOS
converte os agentes do tipo individual que pelo nome foram identificados como sendo coletivos, para o agentes coletivos
-----------------------------------------
1498 agentes individuais convertidos para agentes coletivos

=========================================
CORREÇÃO DOS AGENTES DE PERFIL
garante que todos os usuários tenhma agentes individuais como agentes de perfil
-----------------------------------------
1.	 349	 Se o usuário tem apenas 1 agente individual, coloca esse agente como agente de perfil
2.	 	 Se o usuário tem mais do que 1 agente individual procura o agente seguinto a ordem:
2.1.	 56	 Email igual ao do usuário e que tenha o cpf preenchido
2.2.	 43	 CPF preenchido
2.3.	 6	 Email igual ao do usuário
2.4.	 49	 Primeiro da lista
3.	 	 Se o usuário não possui nenhum agente individual:
3.1.	 131	 Escolhe um agente coletivo que tenha CPF preenchido, não tenha CNPJ preenchido e tenha o email igual ao do usuário
3.2.	 17	 Escolhe um agente coletivo que tenha CPF preenchido, não tenha CNPJ preenchido
3.3.	 137	 Escolhe um agente sem cpf nem cnpj
4.	 1773	 Não foi encontrado nenhum agente para ser o agente principal, cria um novo agente

=========================================
MESCLAGEM DE AGENTES DUPLICADOS
identifica agentes duplicados pelo documento, nome e email, mescla os dados e entidades de propriedade desses agentes e depois exclui os agentes vazios, mantendo apenas 1 agente
-----------------------------------------
Número de agentes com duplicidades: 1478
Número de agentes removidos: 1902

=========================================
CORREÇÃO DE SUBAGENTES INDIVIDUAIS
garante que todos os usuários tenham somente um agente individual
-----------------------------------------
Subagentes removidos: 455
Novos usuários criados: 286
  - com email: 152
  - sem email: 134
```