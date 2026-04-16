# Mark Potter Photography - Next.js Frontend

This repository holds the site files \ dev files \ work process history \ learning documentation \ etc related to migrating my personal website from an old Wordpress website that hasn't been online in almost a decade, to an up to date wordpress install, setup in headless mode, using a next.js front end.

This is probably uninteresting \ not useful to 99% of people, but this is also being used to (better) learn Github DevOps.

This is also being used to (better) learn Kubernetes by deploying to my local mixed-arch K8s cluster running on a variety of hardware, including Raspberry Pi 3b, 3b+, 4, Rock64, Asus Tinkerboard (1), 2 S, AMD64 (vintage 1st gen 64bit Opteron), x86_64 (more modern intel i5); All of the arm hardware is cooled on a custom water cooling loop, and over-clocked ~10-50%; Storage is MicroSD, USB Memstick, USB SATA SSD, USB SATA HDD, Sata SSD, HDD RAID, and NFS shares, optimized to node role. All nodes running <a href="https://github.com/MichaIng/DietPi">DietPi</a> and K3s via <a href="https://github.com/alexellis/k3sup">k3sup</a>, Connectivity via 24port Layer2 switch.

Mixed-Arch puts some constraints on the images used and configuration profiles. RPI nodes have 1gb RAM, 1st Gen tinkerboards are Armv7 (32bit).

This is very much a work in progress, things will be broken, I am not providing support or soliciting feedback.

If you choose to fork or clone this repository - thats on you.

## Project Architecture

```
root/
├── dev.mark-potter.com/                        # PVC Root files
│   └── app/                                    # Next.js app router pages
│       ├── globals.css                         # Global styles
│       ├── HomePage.tsx                        # Homepage layout
│       ├── layout.tsx                          # Base layout
│       └── lib/                                # Utility libraries
│           └── wordpress.ts                    # WordPress API client
│       ├── packages.json                       # Dependencies
│       └── page.tsx
├── components/                                 # React components (TBD)
│       ├── FilterSidebar.tsx
│       ├── ImageDetailViewer.tsx
│       ├── MasonryGrid.tsx
│       └── StaticPageBlock.tsx
├── lib/                                        # Utility libraries
│       └── wordpress.ts                        # WordPress API client
├── next-env.d.ts                               # Static assets
├── next.config.mjs                             # Next.js configuration
├── node_modules/                               # gitignored
├── tailwind.config.js                          # Tailwind CSS configuration
├── tsconfig.json                               # TypeScript configuration
├── package.json                                # Dependencies
├── k8s/                                        # K8s Deployments
│   └──  wordpress/                             # wordpress specific
│       ├── 00-wp-secrets-EXAMPLE.yaml          # example secrets file for db passwords
│       ├── 00.1-m_db-deploy.yaml               # mariadb
│       ├── 01-wp-deploy.yaml                   # wordpress
│       ├── 01.1-php_fpm-config.yaml            # php_fpm php processor (in lieu of apache)
│       ├── 03-nginx_wp-deploy.yaml             # nginx (serving static files)
│       ├── 04-php_my-deploy.yaml               # Optional PHPMyAdmin - useful to have the GUI while working on the database
│       ├── 05-php_my-ingress.yaml
│       ├── 06-wp-frontend.yaml                 # access configuration for frontend
│       └── X02-redisdeploy.yaml                # Not currently in use
│   └── nextjs_frontend/                        # next js specific
│       ├── namespace.yaml
│       ├── pvc.yaml
│       ├── deployment.yaml
│       └── ingress.yaml
├── mcp-server-kubernetes/                      # gitignored
├── new_dev_work
├── venv/                                       # gitignored
├── wordpress_root/
│   └── wp_content/
│       └── plugins/                             # custom plugins used for repairing content and modifying for the new front end
│           └── mp-content-migrator/
│               └── mp-content-migrator.php
│           └── mp-headless-support/
│               ├── admin-gallery.css
│               ├── admin-gallery.js
│               └── mp-headless-support.php
│           └── mp-rest-extensions/
│               └── mp-reset.extensions.php
```

## WordPress API Connection

The Next.js app connects to WordPress via:

Internal cluster URL: `http://nginx-wp.default.svc.cluster.local/wp-json`
Configured in `next.config.mjs` rewrites
Environment variable: `NEXT_PUBLIC_WP_API_URL`
