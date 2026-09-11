(() => {
    'use strict';

    const normalize = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es')
        .replace(/[.\-]/g, '')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim()
        .replace(/\s+/g, ' ');

    const tokens = (value) => normalize(value).split(' ').filter(Boolean).slice(0, 10);

    const matches = (query, ...values) => {
        const queryTokens = tokens(query);
        if (queryTokens.length === 0) {
            return true;
        }
        const haystack = normalize(values.join(' '));
        return queryTokens.every((token) => haystack.includes(token));
    };

    window.mspSearch = Object.freeze({ normalize, tokens, matches });
})();
