import { readFileSync } from 'node:fs'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import Create from './Create.vue'

const axiosGetMock = vi.hoisted(() => vi.fn())
vi.mock('axios', () => ({ default: { get: axiosGetMock } }))

vi.mock('@inertiajs/vue3', async () => {
  const { reactive } = await import('vue')
  return {
  Head: { props: ['title'], template: '<title>{{ title }}</title>' },
  useForm: (initial: Record<string, unknown>) => {
    const data = { ...initial }
    const errors = {} as Record<string, string>
    return reactive({
      ...data,
      errors,
      processing: false,
      transform: () => ({ post: vi.fn() }),
      post: vi.fn(),
    })
  },
  usePage: () => ({ url: '/kol/library/create', props: { auth: { user: { id: 1, name: 'Test', email: 't@t.com' } }, shellNavigation: [] } }),
  }
})

vi.mock('../../Layouts/AuthenticatedLayout.vue', () => ({
  default: { name: 'AuthenticatedLayout', props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}))

const defaultProps = {
  ownerOptions: [{ value: 1, label: 'Test User' }],
  currentUserId: 1,
  canManageViews: true,
}

describe('Kol/Create VI contract', () => {
  afterEach(() => { vi.useRealTimers(); axiosGetMock.mockReset() })

  it('uses a searchable standard nationality selector independent of shipping country', async () => {
    const wrapper = mount(Create, { props: defaultProps })
    const nationality = wrapper.get('[data-testid="kol-nationality-country"]')
    expect(nationality.element.tagName).toBe('INPUT')
    expect(nationality.attributes('role')).toBe('combobox')
    expect(wrapper.find('.searchable-select').exists()).toBe(false)
    expect(wrapper.text()).toContain('国籍地')
    expect(wrapper.text()).toContain('现居国家（收件地址）')
    expect((nationality.element as HTMLInputElement).value).toBe('')
    await nationality.setValue('挪威')
    await nationality.trigger('blur')
    await wrapper.get('input[data-testid="kol-shipping-country"]').setValue('MX')
    await wrapper.get('input[data-testid="kol-shipping-country"]').trigger('blur')
    expect((nationality.element as HTMLInputElement).value).toBe('Norway (NO)')
    expect((wrapper.get('input[data-testid="kol-shipping-country"]').element as HTMLInputElement).value).toBe('Mexico (MX)')
    expect(wrapper.find('[data-testid="custom-kol-country"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('warns on an existing username before submitting a duplicate KOL', async () => {
    vi.useFakeTimers()
    axiosGetMock.mockResolvedValue({ data: {
      exists: true, message: '已经存在这个网红，是否重复创建账号？',
      existing_url: '/kol/library/42',
    } })
    const wrapper = mount(Create, { props: defaultProps })
    await wrapper.get('input[autocomplete="username"]').setValue('_dolordemuelas_')
    await vi.advanceTimersByTimeAsync(480)
    await flushPromises()
    expect(wrapper.get('[data-testid="kol-duplicate-warning"]').text()).toContain('已经存在这个网红，是否重复创建账号？')
    expect(wrapper.get('[data-testid="kol-duplicate-warning"] a').attributes('href')).toBe('/kol/library/42')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
  it('uses erp-form-page wrapper — no mvp-page', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.find('.erp-form-page').exists()).toBe(true)
    expect(wrapper.find('.mvp-page').exists()).toBe(false)
  })

  it('uses erp-form-content inner wrapper', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.find('.erp-form-content').exists()).toBe(true)
  })

  it('uses erp-form-card for section cards — no mvp-card', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.findAll('.erp-form-card').length).toBeGreaterThanOrEqual(5)
    expect(wrapper.find('.mvp-card').exists()).toBe(false)
  })

  it('uses erp-form-grid inside cards', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.findAll('.erp-form-grid').length).toBeGreaterThanOrEqual(1)
  })

  it('uses erp-form-header-row for page header', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.find('.erp-form-header-row').exists()).toBe(true)
  })

  it('uses erp-form-title for page title', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.find('.erp-form-title').exists()).toBe(true)
    expect(wrapper.find('.erp-form-title').text()).toContain('新建 KOL')
  })

  it('uses sticky header actions instead of a bottom action bar', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.find('.kol-sticky-header').exists()).toBe(true)
    expect(wrapper.find('.kol-header-actions').exists()).toBe(true)
    expect(wrapper.find('.erp-form-actions').exists()).toBe(false)
  })

  it('primary submit button uses erp-btn erp-btn-primary', () => {
    const wrapper = mount(Create, { props: defaultProps })
    const btn = wrapper.find('button[type="submit"]')
    expect(btn.classes()).toContain('erp-btn')
    expect(btn.classes()).toContain('erp-btn-primary')
    expect(btn.text()).toContain('保存 KOL')
  })

  it('cancel link uses erp-btn erp-btn-secondary', () => {
    const wrapper = mount(Create, { props: defaultProps })
    const cancelLinks = wrapper.findAll('a.erp-btn-secondary')
    expect(cancelLinks.length).toBeGreaterThan(0)
  })

  it('does not use any native isolated checkbox (social is_active uses segmented control)', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })

  it('shows 基本信息 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('基本信息')
  })

  it('shows 业务画像 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('业务画像')
  })

  it('shows 税务与收款 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('税务与收款')
  })

  it('shows 收件地址 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('收件地址')
  })

  it('shows 社交账号 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('社交账号')
  })

  it('shows 历史带货记录 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('历史带货记录')
  })

  it('shows 内部运营信息 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('内部运营信息')
  })

  it('shows 备注 card heading', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('备注')
  })

  it('does not offer WeChat or Douyin or Telegram as contact fields', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).not.toContain('微信号')
    expect(wrapper.text()).not.toContain('抖音')
    expect(wrapper.text()).not.toContain('Telegram')
  })

  it('keeps shipping contact lean and moves account email into the approved WordPress/Affiliate card', () => {
    const wrapper = mount(Create, { props: defaultProps })
    const addressCard = wrapper.findAll('.erp-form-card').find(card => card.text().includes('收件地址'))
    const accountCard = wrapper.find('.kol-account-card')
    expect(addressCard).toBeTruthy()
    expect(addressCard!.text()).toContain('电话')
    expect(addressCard!.text()).not.toContain('Email')
    expect(accountCard.exists()).toBe(true)
    expect(accountCard.text()).toContain('Email')
    expect(wrapper.text()).not.toContain('WhatsApp')
    expect(wrapper.text()).not.toContain('其他联系方式')
    expect(wrapper.text()).not.toContain('首选联系方式')
    expect(addressCard!.find('.kol-address-grid').exists()).toBe(true)
    expect(addressCard!.findAll('.erp-form-field')).toHaveLength(7)
  })

  it('shows the approved WordPress / Affiliate account fields and password-safety copy', () => {
    const wrapper = mount(Create, { props: defaultProps })
    const card = wrapper.find('.kol-account-card')
    expect(card.exists()).toBe(true)
    expect(card.text()).toContain('WordPress / Affiliate 账号')
    const username = card.find('input[autocomplete="username"]')
    const email = card.find('input[type="email"]')
    expect(username.exists()).toBe(true)
    expect(username.attributes('required')).toBeDefined()
    expect(card.element.querySelector('input[autocomplete="username"]')!.compareDocumentPosition(email.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(email.exists()).toBe(true)
    expect(card.findAll('input[type="password"]')).toHaveLength(2)
    const password = card.find('input[type="password"]')
    expect(password.attributes('minlength')).toBe('7')
    expect(password.attributes('maxlength')).toBeUndefined()
    expect(password.attributes('pattern')).toBe('[A-Za-z].{6,}')
    expect(card.text()).toContain('英文字母开头，长度大于 6 个字符。')
    expect(card.text()).toContain('不保存到 ERP')
    expect(card.find('input[placeholder="例如 ANDY15"]').exists()).toBe(true)
    expect(card.text()).toContain('Affiliate Name')
    expect(card.text()).toContain('Affiliate URL')
    expect(card.find('input[placeholder="默认与 Username 相同"]').exists()).toBe(true)
    expect(card.find('input[placeholder="推广链接标识"]').exists()).toBe(true)
    expect(card.findAll('.kol-credential-rows > .erp-form-field')).toHaveLength(7)
    expect(card.find('input[placeholder="https://www.instagram.com/username/"]').exists()).toBe(true)
    expect(card.text()).toContain('Code 有效期')
    expect(card.text()).toContain('独立标识需 Custom Affiliate Slugs')
  })

  it('defaults cooperation type, joined date, owner and primary social platforms', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('免费发帖')
    expect(wrapper.text()).toContain('长期合作')
    expect(wrapper.text()).toContain('付费发帖')
    const now = new Date()
    const localDate = [
      String(now.getFullYear()).padStart(4, '0'),
      String(now.getMonth() + 1).padStart(2, '0'),
      String(now.getDate()).padStart(2, '0'),
    ].join('-')
    expect((wrapper.find('input[type="date"]').element as HTMLInputElement).value).toBe(localDate)
    const platformSelects = wrapper.findAll('.kol-sa-row select')
    expect(platformSelects).toHaveLength(2)
    expect((platformSelects[0].element as HTMLSelectElement).value).toBe('instagram')
    expect((platformSelects[1].element as HTMLSelectElement).value).toBe('twitter')
    expect((wrapper.findAll('select').find(select => select.text().includes('Test User'))!.element as HTMLSelectElement).value).toBe('1')
    expect(wrapper.text()).not.toContain('法定名称')
  })

  it('shows payout method, tax id and payout account fields', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('Tax ID')
    expect(wrapper.text()).toContain('收款方式')
    expect(wrapper.text()).toContain('收款账户')
  })

  it('shows shipping address fields', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).toContain('收件人')
    expect(wrapper.text()).toContain('详细地址')
    expect(wrapper.text()).not.toContain('地址行 2')
    expect(wrapper.text()).toContain('邮政编码')
  })

  it('does not require monthly GMV or average selling price fields', () => {
    const wrapper = mount(Create, { props: defaultProps })
    expect(wrapper.text()).not.toContain('月度 GMV')
    expect(wrapper.text()).not.toContain('平均售价')
  })

  it('shows historical order count sales-performance entry fields', () => {
    const wrapper = mount(Create, { props: defaultProps })
    const addButtons = wrapper.findAll('button').filter(b => b.text() === '添加记录')
    expect(addButtons.length).toBeGreaterThan(0)
  })

  it('status uses erp-segmented control not a select', () => {
    const wrapper = mount(Create, { props: defaultProps })
    const segmented = wrapper.findAll('.erp-segmented')
    expect(segmented.length).toBeGreaterThanOrEqual(1)
    const statusSegmented = segmented[0]
    expect(statusSegmented.text()).toContain('活跃')
    expect(statusSegmented.text()).toContain('停用')
  })
})

describe('P0 provider preflight rejection guidance', () => {
  it('shows a persistent diagnostic path only for WordPress or WooCommerce identity conflicts', () => {
    const source = readFileSync('resources/js/Pages/Kol/Create.vue', 'utf8')
    expect(source).toContain('providerValidationError')
    expect(source).toContain('data-testid="kol-create-provider-blocked"')
    expect(source).toContain('role="alert" aria-live="assertive"')
    expect(source).toContain('page=affiliate-wp-affiliates')
    expect(source).toContain('post_type=shop_coupon')
  })
})

describe('A8 shared smart input — profile entry', () => {
  it('fills identity, contact, shipping country and coupon but not nationality or password', async () => {
    axiosGetMock.mockResolvedValue({data:{exists:false}})
    const wrapper=mount(Create,{props:defaultProps})
    await wrapper.get('[data-testid="kol-smart-raw"]').setValue('https://x.com/demo_alpha 12K\nFull Name: Demo Person\nCountry: Mexico\nEmail: demo@example.com\nCode: DEMO20\nProduct: Demo Product')
    await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
    await flushPromises()
    expect((wrapper.get('input[autocomplete="username"]').element as HTMLInputElement).value).toBe('demo_alpha')
    expect((wrapper.get('input[autocomplete="email"]').element as HTMLInputElement).value).toBe('demo@example.com')
    expect((wrapper.get('[data-testid="kol-shipping-country"]').element as HTMLInputElement).value).toBe('Mexico (MX)')
    expect((wrapper.get('[data-testid="kol-nationality-country"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('input[autocomplete="new-password"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.get('[data-testid="kol-smart-product-hint"]').text()).toContain('Demo Product')
    expect(axiosGetMock).toHaveBeenCalledWith('/kol/library/duplicate-check',expect.objectContaining({params:expect.objectContaining({wordpress_username:'demo_alpha'})}))
    wrapper.unmount()
  })
  it('does not merge different usernames into an existing draft',async()=>{
    axiosGetMock.mockResolvedValue({data:{exists:false}})
    const wrapper=mount(Create,{props:defaultProps})
    await wrapper.get('input[autocomplete="username"]').setValue('demo_original')
    await wrapper.get('[data-testid="kol-smart-raw"]').setValue('https://x.com/demo_other\nEmail: other@example.com')
    await wrapper.get('[data-testid="kol-smart-parse"]').trigger('click')
    expect(wrapper.get('[data-testid="kol-smart-warning"]').text()).toContain('账号存在冲突')
    expect((wrapper.get('input[autocomplete="email"]').element as HTMLInputElement).value).toBe('')
    wrapper.unmount()
  })
})
