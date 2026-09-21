import { defineConfig } from 'vitepress'

// The markdown in this repository is the source: the site is a view of it, not a copy.
// Everything links the same way on GitHub and on the site, so srcDir is the repository root.
export default defineConfig({
  title: 'Laravel ZipStream',
  description: 'Stream ZIP archives of any size from Laravel, at constant memory.',
  base: '/laravel-zipstream/',
  lastUpdated: true,
  cleanUrls: true,

  srcExclude: [
    'vendor/**',
    'node_modules/**',
    'tests/**',
    'workbench/**',
    'resources/**',
    'lang/**',
    'config/**',
  ],

  // README.md is the front page here, and stays the front page on GitHub.
  rewrites: {
    'README.md': 'index.md',
    'docs/README.md': 'docs/index.md',
  },

  themeConfig: {
    nav: [
      { text: 'Documentation', link: '/docs/' },
      { text: 'Upgrade guide', link: '/UPGRADE' },
      { text: 'Packagist', link: 'https://packagist.org/packages/exeque/laravel-zipstream' },
    ],

    sidebar: [
      {
        text: 'Getting started',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Documentation index', link: '/docs/' },
        ],
      },
      {
        text: 'Building an archive',
        items: [
          { text: 'Adding content', link: '/docs/content' },
          { text: 'Entry options', link: '/docs/entries' },
          { text: 'Output', link: '/docs/output' },
        ],
      },
      {
        text: 'While it runs',
        items: [
          { text: 'Events', link: '/docs/events' },
          { text: 'Progress', link: '/docs/progress' },
        ],
      },
      {
        text: 'Drivers',
        items: [
          { text: 'S3', link: '/docs/drivers/s3' },
          { text: 'Local filesystem', link: '/docs/drivers/local' },
        ],
      },
      {
        text: 'Around it',
        items: [
          { text: 'Configuration', link: '/docs/configuration' },
          { text: 'Testing', link: '/docs/testing' },
          { text: 'Upgrade guide', link: '/UPGRADE' },
        ],
      },
      {
        text: 'Contributing',
        items: [
          { text: 'Testing against S3 with MinIO', link: '/contributing/testing-with-minio' },
        ],
      },
    ],

    search: {
      provider: 'local',
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/ExeQue/laravel-zipstream' },
    ],

    editLink: {
      pattern: 'https://github.com/ExeQue/laravel-zipstream/edit/main/:path',
      text: 'Edit this page on GitHub',
    },

    outline: [2, 3],
  },
})
