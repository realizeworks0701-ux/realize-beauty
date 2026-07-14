/** 曜日別営業時間（day_of_week: 0=日曜〜6=土曜、時刻は HH:MM） */
export interface BusinessHour {
  day_of_week: number
  is_closed: boolean
  open_time: string
  close_time: string
}

export interface BusinessHoursUpdateInput {
  business_hours: BusinessHour[]
}
