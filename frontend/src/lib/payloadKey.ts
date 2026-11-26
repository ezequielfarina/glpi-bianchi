// Mesma normalização de chave de atributo do backend (App\Ui\Write\Payload::attributeKey):
// "Número da linha (DDD)" → "numero_da_linha_ddd".
export const Payload = {
  key(label: string): string {
    return label
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
  },
}
