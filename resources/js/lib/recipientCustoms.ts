export type RecipientCustomsSpec = {
  code: string
  label: string
  inputLabel: string
  hint: string
  required: boolean
}

const countries: Array<RecipientCustomsSpec & { aliases: string[] }> = [
  { code: 'CL', label: '智利', inputLabel: '收件人 RUT / RUN（税号）',
    hint: 'Chile / 智利：请填写收件人的 RUT / RUN。', required: true,
    aliases: ['CL', 'Chile', 'Chili', '智利'] },
  { code: 'PE', label: '秘鲁', inputLabel: '收件人 DNI / RUC / CE（身份证或税号）',
    hint: 'Peru / 秘鲁：可填写 DNI、RUC 或承运商认可的 CE。', required: true,
    aliases: ['PE', 'Peru', 'Perú', '秘鲁'] },
  { code: 'AR', label: '阿根廷', inputLabel: '收件人 CUIT / CUIL（税号）',
    hint: 'Argentina / 阿根廷：请填写收件人 CUIT / CUIL。', required: true,
    aliases: ['AR', 'Argentina', '阿根廷'] },
  { code: 'BR', label: '巴西', inputLabel: '收件人 CPF / CNPJ（税号）',
    hint: 'Brazil / 巴西：个人填 CPF，企业填 CNPJ。', required: true,
    aliases: ['BR', 'Brazil', 'Brasil', '巴西'] },
  { code: 'MX', label: '墨西哥', inputLabel: '收件人 RFC / 认可的身份证明（税号）',
    hint: 'Mexico / 墨西哥：优先 RFC；特殊身份按承运商要求提供 CURP 或其他证件。', required: true,
    aliases: ['MX', 'Mexico', 'México', '墨西哥'] },
  { code: 'KR', label: '韩国', inputLabel: '收件人 PCCC / 身份证号（清关）',
    hint: 'Korea / 韩国：优先个人通关码 PCCC；其他身份文件按承运商要求。', required: true,
    aliases: ['KR', 'Korea', 'South Korea', 'Republic of Korea', '한국', '韩国', '韩国（南）'] },
  { code: 'NO', label: '挪威', inputLabel: '收件人身份号码（仅承运商需要时）',
    hint: 'Norway / 挪威：VOEC 是卖家/平台用于申报的 7 位号码，不是收件人税号。NOK 3000 门槛按单件商品计算；如承运商要求，可在此填写收件人身份号码。',
    required: false, aliases: ['NO', 'Norway', 'Norge', '挪威'] },
]

const normalize = (value: string) => value.trim().normalize('NFKD')
  .replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').toLowerCase()

export const recipientCustomsSpec = (country: string | null | undefined): RecipientCustomsSpec | null => {
  if (!country?.trim()) return null
  const match = countries.find(item => item.aliases.some(alias => normalize(alias) === normalize(country)))
  return match ? { code: match.code, label: match.label, inputLabel: match.inputLabel, hint: match.hint, required: match.required } : null
}

export const recipientCountrySuggestions = countries.map(item => ({ code: item.code, label: item.label }))
