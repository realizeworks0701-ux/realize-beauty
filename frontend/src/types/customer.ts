/** 0: 不明 / 1: 男性 / 2: 女性 / 9: その他 */
export type Gender = 0 | 1 | 2 | 9

export interface Customer {
  id: number
  name: string
  kana: string
  gender: Gender | null
  birthday: string | null
  phone: string | null
  email: string | null
  memo: string | null
  first_visit_at: string | null
  last_visit_at: string | null
  created_at: string
  updated_at: string
}

export interface CustomerInput {
  name: string
  kana: string
  gender?: Gender | null
  birthday?: string | null
  phone?: string | null
  email?: string | null
  memo?: string | null
}

export interface CustomerListParams {
  keyword?: string
  page?: number
  per_page?: number
  sort?: string
  gender?: Gender
  visited_after?: string
  visited_before?: string
}
