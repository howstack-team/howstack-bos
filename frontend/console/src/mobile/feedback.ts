import { createContext, useContext } from 'react'

type Feedback = (content: string, type?: 'success' | 'error' | 'info') => void
export const FeedbackContext = createContext<Feedback>(() => undefined)
export const useFeedback = () => useContext(FeedbackContext)
