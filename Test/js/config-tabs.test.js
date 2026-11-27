// O que Resources/js/config-tabs.js expõe: a instalação da callback que o core chama com o bloco. A troca de abas em si
// (mover os grupos para abas do modal) foi conferida com o HTML renderizado num DOM real.
//
//   docker exec mautic_app sh -c "cd /var/www/html/docroot/plugins/DialogHSMBundle && node --test 'Test/js/*.test.js'"
'use strict';

const test   = require('node:test');
const assert = require('node:assert');
const api    = require('../../Resources/js/config-tabs.js');

// um jQuery em que todo elemento é vazio (sem conteúdo de abas, sem lista de abas)
const empty = () => {
    const node = {length: 0, closest: () => node, prev: () => node, prevAll: () => node, first: () => node, attr: () => undefined};
    return node;
};

test('install: a callback do template fica em Mautic e entrega o bloco ao moveToTabs', () => {
    const Mautic = {};
    api.install(empty, Mautic);

    assert.strictEqual(typeof Mautic.dialoghsmConfigTabs, 'function');
    assert.doesNotThrow(() => Mautic.dialoghsmConfigTabs({}));
});

test('install: sem jQuery ou sem Mautic nada é tocado', () => {
    assert.doesNotThrow(() => api.install(null, {}));
    assert.doesNotThrow(() => api.install(() => ({}), null));
});

test('moveToTabs: bloco fora de um conteúdo de abas fica onde está', () => {
    assert.strictEqual(api.moveToTabs(empty, {}), 0);
});
