import { cleanConfig, MAX_PORTFOLIOS, PORTFOLIO_VERSION } from './portfolio-engine.js?v=20261028-v13y';

export const STORAGE_KEY = 'sms.asset-backtester.portfolios.v1';
export function parsePortfolioFile(text) {
  if (typeof text !== 'string' || text.length > 200000) throw new Error('Portfoliodatei ist zu groß.');
  let envelope;
  try { envelope = JSON.parse(text); } catch { throw new Error('Die Datei enthält kein gültiges JSON.'); }
  if (envelope?.format !== 'sms-backtester-portfolios' || envelope.version !== PORTFOLIO_VERSION
    || !Array.isArray(envelope.portfolios) || envelope.portfolios.length > MAX_PORTFOLIOS) {
    throw new Error('Unbekanntes Dateiformat oder mehr als fünf Portfolios.');
  }
  const portfolios = envelope.portfolios.map(cleanConfig);
  const ids = portfolios.map((p) => p.id).filter(Boolean);
  if (new Set(ids).size !== ids.length) throw new Error('Doppelte Portfolio-Kennungen in der Datei.');
  return portfolios;
}
export function exportPortfolios(portfolios) {
  if (portfolios.length > MAX_PORTFOLIOS) throw new Error('Maximal fünf eigene Portfolios.');
  return JSON.stringify({ format: 'sms-backtester-portfolios', version: PORTFOLIO_VERSION,
    exportedAt: new Date().toISOString(), portfolios: portfolios.map(cleanConfig) }, null, 2);
}
export class PortfolioStore {
  constructor(storage) { this.storage = storage; }
  load() {
    let text;
    try { text = this.storage.getItem(STORAGE_KEY); } catch { throw new Error('Browserspeicher ist nicht zugänglich. Export bleibt möglich.'); }
    const saved = text === null ? [] : parsePortfolioFile(text);
    if (saved.some((p) => !p.id)) throw new Error('Lokaler Portfoliospeicher enthält ungültige Kennungen. Daten wurden nicht überschrieben.');
    return saved;
  }
  write(portfolios) {
    const text = exportPortfolios(portfolios);
    try { this.storage.setItem(STORAGE_KEY, text); }
    catch { throw new Error('Lokales Speichern fehlgeschlagen. Speicherzugriff prüfen oder JSON exportieren.'); }
    return portfolios.map(cleanConfig);
  }
  save(portfolios, config, { duplicate = false } = {}) {
    const item = cleanConfig(config);
    const index = duplicate ? -1 : portfolios.findIndex((p) => p.id && p.id === item.id);
    if (index < 0 && portfolios.length >= MAX_PORTFOLIOS) throw new Error('Alle fünf Speicherplätze sind belegt. Vorhandenes Portfolio laden und ersetzen oder löschen.');
    if (index < 0) item.id = globalThis.crypto?.randomUUID?.() || `portfolio-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const next = [...portfolios];
    if (index < 0) next.push(item); else next[index] = item;
    return { portfolios: this.write(next), item };
  }
  remove(portfolios, id) { return this.write(portfolios.filter((p) => p.id !== id)); }
  import(portfolios, text) {
    const incoming = parsePortfolioFile(text);
    if (incoming.length + portfolios.length > MAX_PORTFOLIOS) throw new Error('Import überschreitet fünf Speicherplätze. Zuerst Platz schaffen.');
    const copies = incoming.map((p, i) => ({ ...p, id: globalThis.crypto?.randomUUID?.() || `import-${Date.now()}-${i}` }));
    return this.write([...portfolios, ...copies]);
  }
}
