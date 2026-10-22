import { createI18n } from 'vue-i18n'

export default (messages) => {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    globalInjection: true,
    messages,
  })

  // Existing screens and installed modules use the legacy pluralisation name.
  i18n.global.tc = (...args) => i18n.global.t(...args)

  return i18n
}
