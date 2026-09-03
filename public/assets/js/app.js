/* =============================================================================
   CREA Pro-Link | Comportamentos da interface
   jQuery, conforme o item 8.1.3 do Termo de Referência. Progressivo: toda a
   aplicação funciona sem JavaScript, e este arquivo apenas melhora o uso.
   ========================================================================== */

(function ($) {
    'use strict';

    var app = {
        urlBase: function () {
            return $('meta[name="app-url"]').attr('content') || '';
        },

        token: function () {
            return $('meta[name="csrf-token"]').attr('content') || '';
        }
    };

    /* --- Contadores de caracteres ---------------------------------------- */
    function iniciarContadores() {
        $('[data-contador]').each(function () {
            var $campo = $(this);
            var limite = parseInt($campo.attr('maxlength') || $campo.data('contador'), 10);

            if (!limite) {
                return;
            }

            var $saida = $('<div class="form-text text-end" aria-live="polite"></div>');
            $campo.after($saida);

            function atualizar() {
                var restantes = limite - ($campo.val() || '').length;
                $saida.text(restantes + ' caractere(s) restante(s)');
                $saida.toggleClass('text-danger', restantes < 20);
            }

            $campo.on('input', atualizar);
            atualizar();
        });
    }

    /* --- Filtro de busca nas listas de competências ---------------------- */
    function iniciarFiltroCompetencias() {
        $('[data-filtro-alvo]').on('input', function () {
            var termo = ($(this).val() || '').toString().toLowerCase().trim();
            var $alvo = $($(this).data('filtro-alvo'));

            $alvo.find('[data-rotulo]').each(function () {
                var rotulo = ($(this).data('rotulo') || '').toString().toLowerCase();
                $(this).toggle(termo === '' || rotulo.indexOf(termo) !== -1);
            });

            $alvo.find('[data-grupo]').each(function () {
                var $grupo = $(this);
                $grupo.toggle($grupo.find('[data-rotulo]:visible').length > 0);
            });
        });
    }

    /* --- Contagem de competências selecionadas --------------------------- */
    function iniciarContagemSelecionadas() {
        var $saida = $('[data-contagem-selecionadas]');

        if (!$saida.length) {
            return;
        }

        function atualizar() {
            var total = $('input[name="competencias[]"]:checked').length;
            $saida.text(total);
            $saida.closest('[data-aviso-selecao]').toggleClass('text-danger', total === 0);
        }

        $(document).on('change', 'input[name="competencias[]"]', atualizar);
        atualizar();
    }

    /* --- Municípios do Amazonas ------------------------------------------ */
    function iniciarMunicipios() {
        var $uf = $('[data-uf-origem]');

        if (!$uf.length) {
            return;
        }

        function carregar() {
            var uf = ($uf.val() || '').toString();
            var $lista = $('#lista-municipios');

            if (!$lista.length || uf !== 'AM') {
                $('#lista-municipios').empty();
                return;
            }

            $.getJSON(app.urlBase() + '/api/municipios/' + uf)
                .done(function (resposta) {
                    var opcoes = (resposta.municipios || []).map(function (nome) {
                        return $('<option>').attr('value', nome);
                    });
                    $lista.empty().append(opcoes);
                });
        }

        $uf.on('change', carregar);
        carregar();
    }

    /* --- Consulta prévia na API oficial durante o cadastro --------------- */
    function iniciarVerificacaoCrea() {
        var $botao = $('#verificar-crea');

        if (!$botao.length) {
            return;
        }

        $botao.on('click', function (evento) {
            evento.preventDefault();

            var tipo = $('input[name="tipo_vinculo"]:checked').val();
            var documento = tipo === 'EMPRESA' ? $('#cnpj').val() : $('#cpf').val();
            var $saida = $('#resultado-crea');

            if (!documento) {
                $saida.attr('class', 'alert alert-warning mt-3')
                    .text('Informe o documento antes de consultar.')
                    .removeAttr('hidden');
                return;
            }

            $botao.prop('disabled', true);
            $saida.attr('class', 'alert alert-secondary mt-3')
                .text('Consultando a base oficial do CREA-AM...')
                .removeAttr('hidden');

            $.ajax({
                url: app.urlBase() + '/cadastrar/verificar-crea',
                method: 'POST',
                data: { tipo_vinculo: tipo, documento: documento, _token: app.token() },
                dataType: 'json'
            }).done(function (resposta) {
                if (!resposta.sucesso) {
                    $saida.attr('class', 'alert alert-warning mt-3').text(resposta.mensagem);
                    return;
                }

                var dados = resposta.dados || {};
                var linhas = [];

                Object.keys(dados).forEach(function (chave) {
                    if (dados[chave]) {
                        linhas.push('<dt class="col-sm-4 text-capitalize">' + chave.replace(/_/g, ' ') +
                            '</dt><dd class="col-sm-8">' + $('<div>').text(dados[chave]).html() + '</dd>');
                    }
                });

                $saida.attr('class', 'alert alert-success mt-3').html(
                    '<strong>Registro localizado na base oficial.</strong>' +
                    '<dl class="row mb-0 mt-2 small">' + linhas.join('') + '</dl>'
                );
            }).fail(function (xhr) {
                var mensagem = 'Não foi possível consultar a API oficial agora.';

                if (xhr.responseJSON && xhr.responseJSON.mensagem) {
                    mensagem = xhr.responseJSON.mensagem;
                }

                $saida.attr('class', 'alert alert-warning mt-3').text(mensagem);
            }).always(function () {
                $botao.prop('disabled', false);
            });
        });
    }

    /* --- Campos de documento conforme o tipo de cadastro ----------------- */
    function iniciarTipoVinculo() {
        var $radios = $('input[name="tipo_vinculo"]');

        if (!$radios.length) {
            return;
        }

        function aplicar() {
            var tipo = $radios.filter(':checked').val();
            var pessoaJuridica = tipo === 'EMPRESA' || tipo === 'TERCEIRO_PJ';
            var registrado = tipo === 'PROFISSIONAL' || tipo === 'EMPRESA';

            $('[data-campo="cpf"]').toggle(!pessoaJuridica);
            $('[data-campo="cnpj"]').toggle(pessoaJuridica);
            $('[data-bloco-crea]').toggle(registrado);
            $('#rotulo-nome').text(pessoaJuridica ? 'Razão social' : 'Nome completo');

            $('#cpf').prop('required', !pessoaJuridica);
            $('#cnpj').prop('required', pessoaJuridica);
        }

        $radios.on('change', aplicar);
        aplicar();
    }

    /* --- Confirmação de ações destrutivas -------------------------------- */
    function iniciarConfirmacoes() {
        $(document).on('submit', 'form[data-confirmar]', function (evento) {
            var mensagem = $(this).data('confirmar');

            if (!window.confirm(mensagem)) {
                evento.preventDefault();
            }
        });
    }

    /* --- Data de término desabilitada em experiência em andamento -------- */
    function iniciarExperienciaAtual() {
        var $atual = $('#atual');

        if (!$atual.length) {
            return;
        }

        function aplicar() {
            var emAndamento = $atual.is(':checked');
            $('#dt_fim').prop('disabled', emAndamento);

            if (emAndamento) {
                $('#dt_fim').val('');
            }
        }

        $atual.on('change', aplicar);
        aplicar();
    }

    /* --- Rolagem da conversa para a última mensagem ---------------------- */
    function iniciarRolagemConversa() {
        var $lista = $('.lista-mensagens');

        if ($lista.length) {
            $lista.scrollTop($lista[0].scrollHeight);
        }
    }

    /* --- Envio automático dos filtros ao mudar a ordenação --------------- */
    function iniciarOrdenacao() {
        $('[data-submete-formulario]').on('change', function () {
            $(this).closest('form').trigger('submit');
        });
    }

    $(function () {
        iniciarContadores();
        iniciarFiltroCompetencias();
        iniciarContagemSelecionadas();
        iniciarMunicipios();
        iniciarVerificacaoCrea();
        iniciarTipoVinculo();
        iniciarConfirmacoes();
        iniciarExperienciaAtual();
        iniciarRolagemConversa();
        iniciarOrdenacao();
    });
})(jQuery);
