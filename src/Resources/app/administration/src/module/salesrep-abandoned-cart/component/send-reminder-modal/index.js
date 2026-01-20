import template from './send-reminder-modal.html.twig';

const { Component } = Shopware;

Component.register('send-reminder-modal', {
  template,

  props: {
    visible: { type: Boolean, default: false },
    cart: { type: Object, required: true },
  },

  data() {
    return {
      isSending: false,
      note: '',
      includeItems: true,
    };
  },

  methods: {
    onClose() {
      this.$emit('close');
    },

    async onConfirm() {
      this.isSending = true;
      try {
        this.$emit('confirm', {
          note: this.note,
          includeItems: this.includeItems,
        });
      } finally {
        this.isSending = false;
      }
    },
  },
});
