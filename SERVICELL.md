# CortenDesk — versiunea SERVICELL

Fork al [marcpope/cortendesk](https://github.com/marcpope/cortendesk) cu două
module în plus:

- **Enrollment Keys** (Manage → Enrollment Keys): câte o cheie per client
  (cont). Un PC care instalează un client construit cu cheia apare direct în
  contul acelui client, aprobat, fără logare. Cheia poate doar să adauge PC-uri
  în contul ei: nu dă acces la PC-uri, nu poate muta sau șterge dispozitive.
- **Client Builder** (System → Client Builder): construiește clientul RustDesk
  personalizat (nume, iconiță, logo, server, cheie de înrolare) pe GitHub
  Actions, în fork-ul `Servicell2026/rdgen`, și aduce installer-ele în Client
  Downloads. Consola nu trebuie să fie accesibilă din internet: datele merg
  printr-un draft release temporar, șters automat.

## Cum funcționează cheia de înrolare

Clientul RustDesk trimite la server opțiunea încorporată `preset-user-name` la
fiecare `sysinfo`. Client Builder pune cheia (`ek_…`) în această opțiune, iar
serverul (`App\Services\KeyEnrollment`) o recunoaște după hash și:

- PC nou → proprietar = contul cheii, grupul cheii, status aprobat;
- PC existent fără proprietar sau în așteptare → îl preia și îl aprobă;
- PC al altui utilizator → nu se atinge;
- cheie revocată, expirată sau plină → ignorată (PC-ul rămâne „pending” dacă e
  activă aprobarea dispozitivelor).

Build-urile cu cheie nu se publică niciodată pe pagina publică `/downloads`;
se descarcă din consolă și se trimit clientului.

## Configurare o singură dată

1. **GitHub → `Servicell2026/rdgen`**
   - Actions: activează workflow-urile;
   - Settings → Secrets and variables → Actions → `ZIP_PASSWORD` = o parolă lungă.
2. **Token pentru consolă**: GitHub → Settings → Developer settings →
   Fine-grained tokens → acces doar la `Servicell2026/rdgen`, permisiuni
   **Contents: Read and write** și **Actions: Read and write**.
3. **Consolă → Client Builder → Builder settings**: repository
   `Servicell2026/rdgen`, tokenul, aceeași parolă ca `ZIP_PASSWORD`, adresa
   publică (DDNS) și adresa publică a API-ului → *Save & test*.

## Imaginea Docker

`.github/workflows/servicell-image.yml` publică la fiecare push pe `main`
imaginea `ghcr.io/servicell2026/cortendesk:servicell`. Pachetul trebuie făcut
public o dată (GitHub → Packages → cortendesk → Package settings → Change
visibility) sau adăugat ghcr.io cu token în Container Station → Registries.

Pe QNAP se schimbă doar linia `image:` din aplicația `cortendesk`; datele
(volumul `cortendesk-data`) rămân.

## Teste

```bash
composer install
php vendor/bin/pest      # testele pentru cheile de înrolare și Client Builder
```
