import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Auth Center 文档',
  description: '统一身份认证系统 · 开发者对接文档',
  lang: 'zh-CN',
  base: '/docs/',
  appearance: true,
  lastUpdated: true,
  cleanUrls: false,

  head: [
    ['meta', { name: 'theme-color', content: '#ffa726' }],
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/docs/favicon.svg' }],
  ],

  themeConfig: {
    logo: '/favicon.svg',
    siteTitle: 'Auth Center',
    outline: { level: [2, 3], label: '本页目录' },
    search: {
      provider: 'local',
      options: {
        translations: {
          button: { buttonText: '搜索文档', buttonAriaLabel: '搜索文档' },
          modal: { noResultsText: '未找到相关结果', resetButtonTitle: '清除', footer: { selectText: '选择', navigateText: '切换', closeText: '关闭' } },
        },
      },
    },
    nav: [
      { text: '对接指南', link: '/guide/quickstart' },
      { text: 'API 参考', link: '/api/overview' },
      { text: '控制台', link: 'https://<AUTH_SERVER>/' },
    ],
    sidebar: {
      '/guide/': [
        {
          text: '对接指南',
          items: [
            { text: '在线体验', link: '/guide/demo' },
            { text: '简介', link: '/guide/intro' },
            { text: '快速对接（3 步）', link: '/guide/quickstart' },
            { text: 'OAuth 授权码流程', link: '/guide/oauth-flow' },
            { text: '对接示例代码', link: '/guide/examples' },
            { text: '示例 SDK', link: '/guide/sdks' },
            { text: '应用与密钥管理', link: '/guide/apps-keys' },
          ],
        },
      ],
      '/api/': [
        {
          text: 'API 参考',
          items: [
            { text: '接口总览', link: '/api/overview' },
            { text: '认证接口', link: '/api/auth' },
            { text: '用户资源接口', link: '/api/user' },
            { text: '控制台管理接口', link: '/api/console' },
            { text: '错误码', link: '/api/errors' },
          ],
        },
      ],
    },
    docFooter: { prev: '上一篇', next: '下一篇' },
    darkModeSwitchLabel: '外观',
    lightModeSwitchTitle: '切换到浅色模式',
    darkModeSwitchTitle: '切换到暗色模式',
    sidebarMenuLabel: '菜单',
    returnToTopLabel: '回到顶部',
    lastUpdated: { text: '最后更新于', formatOptions: { dateStyle: 'short', timeStyle: 'short' } },
  },
})
