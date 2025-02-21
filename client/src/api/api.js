import axios from 'axios';

const API = axios.create({ baseURL: '/api' });

export const analyzeResume = (formData) => API.post('/analyze', formData);
export const fetchJobs = (query) => API.get(`/jobs?search=${query}`);
