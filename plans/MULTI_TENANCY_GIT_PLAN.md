# Plano: MyAAC Multi-Tenancy via Git

## Visão Geral
Transformar o MyAAC em um sistema verdadeiramente multi-tenancy onde o usuário pode adicionar múltiplos servidores via UI, cada um com seu próprio repositório Git, branch e caminhos de configuração.

## Problema Atual
- Configuração de servidores via arquivo JSON estático (config/servers.json)
- Instalador pede apenas "server_path" único
- Não suporta múltiplos servidores dinamicamente

## Solução Proposta

### Etapa 1: Remover Configuração Estática
- Remover `config/servers.json` do repositório
- Configuração deve ser feita via UI

### Etapa 2: Modificar Instalador (UI)

#### Campos do Formulário:
| Campo | Tipo | Obrigatório | Default |
|-------|------|--------------|---------|
| server_name | text | sim | - |
| git_repo | text | sim | - |
| git_branch | text | sim | main |
| config_path | text | não | config/config.lua |
| data_path | text | não | data/ |
| ssh_private_key | textarea (multiline) | não | (usa env var) |

#### Descrição dos Campos:

1. **Server Name**: Nome de identificação do servidor (ex: "Sovereign")

2. **Git Repository URL**: URL do repositório Git (ex: git@github.com:izziot/canary.git ou https://github.com/izziot/canary.git)

3. **Git Branch**: Branch do repositório (ex: test/sovereign)

4. **Config Path**: Caminho do arquivo config.lua dentro do repositório (ex: config/config.sovereign.lua)

5. **Data Path**: Caminho para a pasta data dentro do repositório (ex: data/)

6. **Private SSH Key (Deploy Key)**: 
   - Tipo: Textarea multiline
   - Descrição: "Cole aqui a chave privada da deploy key. Se vazio, usará a variável de ambiente MYAAC_CANARY_REPO_KEY."
   - Obrigatório: Não (usa fallback)
   - Fallback: MYAAC_CANARY_REPO_KEY do ambiente

### Etapa 3: Lógica de Clone

#### Fluxo de Validação:
```
1. Usuário preenche campos no formulário
   ↓
2. Se ssh_private_key vazio:
   - Verificar MYAAC_CANARY_REPO_KEY disponível (buildtime)
   - Se disponível → usar ela
   - Se não disponível → erro "Configure a variável MYAAC_CANARY_REPO_KEY ou forneça a chave SSH"
   ↓
3. Se ssh_private_key preenchida:
   - Usar a chave inputada para clone
   ↓
4. Clonar repo com sparse checkout:
   - git clone --depth=1 --branch [git_branch] --sparse [git_repo] /srv/servers/[server_name]
   - git sparse-checkout set [config_path] [data_path]
   ↓
5. Verificar se config.lua encontrado
   ↓
6. Continuar instalação normalmente
```

### Etapa 4: Painel Admin (Post-Install)
- Permitir adicionar/editar/remover servidores após instalado
- Cada servidor pode ter repo/branch diferente
- Interface similar ao instalador

## Arquivos a Modificar

| Arquivo | Ação |
|---------|------|
| config/servers.json | REMOVER |
| install/steps/4-config.php | Adicionar campos de UI |
| system/templates/install.config.html.twig | Novo template com campos dinâmicos |
| install/index.php | Validar campos e fazer clone |
| install/includes/config.php | Suportar caminhos dinâmicos |
| admin/pages/servers.php | NOVO - Gerenciar servidores |

## Estrutura de Dados (Banco)

### Tabela: servers
```sql
CREATE TABLE `servers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `git_repo` TEXT NOT NULL,
  `git_branch` VARCHAR(255) NOT NULL DEFAULT 'main',
  `config_path` VARCHAR(255) NOT NULL DEFAULT 'config/config.lua',
  `data_path` VARCHAR(255) NOT NULL DEFAULT 'data/',
  `server_path` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);
```

## Notas de Implementação

1. **Segurança**: Nunca expor chaves SSH no frontend
2. **Performance**: Usar sparse-checkout para minimizar download
3. **UX**: Validar campos antes de tentar clone
4. **Fallback**: Sempre priorizar env var sobre input manual
5. **Multi-tenancy**: Um account deve funcionar em todos os servidores configurados

## Data de Criação: 2026-02-13
## Autor: opencode (planejado para izziOT)
