import template from './sw-order-list.html.twig';

const { Component } = Shopware;

Component.override('sw-order-list', {
    template,
});
