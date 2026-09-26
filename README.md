# CREA Pro-Link

Plataforma do Desafio CREA-AM para conectar profissionais e contratantes de
serviços de engenharia, agronomia e geociências.

O sistema reúne perfis profissionais, demandas, consulta a ARTs e CATs pela
API do CREA, manifestações de interesse e mensagens entre os participantes.
A aplicação usa PHP, MariaDB, Twig, Bootstrap e jQuery.

## Hospedagem

- [Aplicação](https://crea-prolink-app.cveblt.easypanel.host)
- [Easypanel](https://easypanel.daboua.com), projeto `crea-prolink`

A aplicação roda em uma VPS no Brasil, exigência da API oficial do CREA-AM.
O deploy é disparado pelo Easypanel a partir da branch `main`.

## Instalação e documentação

Para executar o projeto, siga o [guia de instalação](_arq/README.md).
Ele inclui a configuração com Docker, as variáveis de ambiente e as contas
de demonstração.

- [Arquitetura](_arq/ARQUITETURA.md)
- [Estrutura de diretórios](_arq/ESTRUTURA-DIRETORIOS.md)
- [Requisitos e telas correspondentes](_arq/REQUISITOS.md)
- [Dependências](_arq/DEPENDENCIAS.md)
- [Segurança](_arq/SEGURANCA.md)
- [Modelo do banco de dados](_arq/mer/mer.md)
- [Publicação no Easypanel](_arq/PUBLICACAO.md)
