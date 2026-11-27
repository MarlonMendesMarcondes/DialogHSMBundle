/*
 * Abas da configuração do plugin (Configurações > Plugins > DialogHSM). O core desenha o formulário inteiro na aba
 * "Habilitar/Autenticar" e não deixa plugin criar aba; Resources/views/FormTheme/config_auth.html.twig marca o bloco com
 * data-onload-callback="dialoghsmConfigTabs" e este script move cada grupo (menos o primeiro, que fica com "Ativo") e o
 * histórico de mudanças para abas próprias. Os campos continuam dentro do <form>: o que é gravado não muda.
 *
 * Depois de um salvamento com erro, a primeira aba com campo inválido fica aberta, para o erro não ficar escondido.
 * Sem o script, tudo fica na primeira aba, separado por títulos.
 *
 * Mesmo mecanismo das abas Sobre/Histórico do MauticPatchesBundle.
 */
(function (root) {
    'use strict';

    var PREFIX = 'dialoghsm-config-';

    /** O core chama Mautic.dialoghsmConfigTabs com o bloco quando o conteúdo do modal está na tela. */
    function moveToTabs($, element) {
        var $root = $(element);
        var $content = $root.closest('.tab-content');
        // No modal do IntegrationsBundle a lista de abas vem logo antes do conteúdo, dentro do <form>;
        // no modal antigo (PluginBundle) ela vem antes do <form>
        var $tabs = $content.prevAll('ul.nav-tabs').first();
        if (!$tabs.length) { $tabs = $content.closest('form').prev('ul.nav-tabs'); }

        if (!$content.length || !$tabs.length || $root.attr('data-tabs-done')) { return 0; }

        var $firstWithError = null;
        var moved = 0;

        $root.children('.dialoghsm-config-group').each(function (index) {
            var $group = $(this);

            if (0 === index) { return; } // o primeiro grupo fica na aba do core

            var paneId = PREFIX + $group.attr('data-group');
            var $tab = $('<li></li>').attr('id', paneId + '-tab')
                .append($('<a role="tab" data-toggle="tab"></a>').attr('href', '#' + paneId).text($group.attr('data-tab-label')));
            var $pane = $('<div class="tab-pane fade bdr-w-0"></div>').attr('id', paneId);

            $group.children('.dialoghsm-config-group__title').remove();
            $pane.append($group);
            $tabs.append($tab);
            $content.append($pane);
            moved++;

            if ($group.find('.has-error').length) {
                $tab.find('a').addClass('text-danger');
                if (null === $firstWithError) { $firstWithError = $tab; }
            }
        });

        $root.attr('data-tabs-done', '1');

        // Erro só em aba nova e nenhum na primeira: abre a aba do erro
        if (null !== $firstWithError && !$root.find('.has-error').length) {
            $tabs.children('li').removeClass('active');
            $content.children('.tab-pane').removeClass('in active');
            $firstWithError.addClass('active');
            $($firstWithError.find('a').attr('href')).addClass('in active');
        }

        return moved;
    }

    function install($, Mautic) {
        if (!$ || !Mautic) { return; }
        Mautic.dialoghsmConfigTabs = function (element) { moveToTabs($, element); };
    }

    var api = {install: install, moveToTabs: moveToTabs};

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.dialoghsmConfigTabsApi = api;
    }
})(typeof window !== 'undefined' ? window : this);
