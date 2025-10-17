# Enterprise Cybersecurity Solutions vs CIS Controls

This catalog maps widely deployed enterprise cybersecurity vendors and flagship
products to the CIS Critical Security Controls v8 (CIS-01 through CIS-18). The
focus is on how each product materially supports implementation of specific
safeguards. Product portfolios and feature sets evolve quickly; confirm current
capabilities, licensing tiers, and integrations with the vendor before making
procurement decisions. Coverage emphasises primary use-cases, not every possible
scenario or third-party integration.

## Reading the mapping

- **Controls** use the `CIS-##` shorthand from the CIS v8 framework.
- **Rationale** highlights the core security outcome the product enables.
- Multiple products are often required to close a single control family.
- Process design, staffing, and managed services remain essential alongside
  tooling investments.

## Microsoft

- **Defender for Endpoint** — CIS-04, CIS-07, CIS-08, CIS-10, CIS-13, CIS-17.
  Attack surface reduction, vulnerability insights, rich endpoint telemetry,
  malware prevention, network protection, and automated response workflows.
- **Defender for Office 365** — CIS-03, CIS-09, CIS-10. Email filtering, safe
  attachment detonation, sensitive data detection, and phishing disruption.
- **Microsoft Sentinel** — CIS-08, CIS-13, CIS-17. Cloud-native SIEM that
  centralises logs, analytics, hunting, and incident orchestration.
- **Microsoft Entra ID (Azure AD)** — CIS-05, CIS-06, CIS-15. Central identity
  governance, adaptive access policies, and partner/service provider controls.
- **Microsoft Purview Compliance & DLP** — CIS-03, CIS-04, CIS-11. Data
  discovery, classification, retention, insider risk analytics, and recovery
  safeguards across cloud and endpoints.

## Palo Alto Networks

- **PA-Series & Prisma Access NGFW** — CIS-04, CIS-09, CIS-12, CIS-13. Secure
  configuration baselines, web and DNS inspection, segmentation, and network
  threat prevention on-premises and SASE.
- **Cortex XDR** — CIS-04, CIS-07, CIS-08, CIS-10, CIS-13, CIS-17. Endpoint
  baselining, vulnerability visibility, analytics, malware blocking, network
  detection, and guided response actions.
- **Cortex XSOAR** — CIS-17, CIS-18. Incident runbooks, automation, case
  management, and purple-team validation of response steps.
- **Prisma Cloud** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Cloud asset
  inventory, configuration assessment, data security, and workload protection.

## Cisco Security

- **Cisco Secure Firewall (Firepower, ASA X)** — CIS-04, CIS-09, CIS-12,
  CIS-13. Hardened network gateways with IPS, URL filtering, and segmentation.
- **Cisco Secure Endpoint** — CIS-04, CIS-07, CIS-10, CIS-13. Endpoint
  hardening, vulnerability assessment, malware prevention, and network control.
- **Cisco Umbrella** — CIS-09, CIS-10, CIS-13. DNS-layer protection, secure web
  gateway, and cloud-delivered firewall services.
- **Duo Security** — CIS-05, CIS-06, CIS-15. MFA, device trust posture, and
  access policies for third-party and workforce users.
- **Cisco SecureX/SIEM** — CIS-08, CIS-17. Integrated logging, case
  management, automation, and threat-hunting capabilities.

## CrowdStrike

- **Falcon Prevent & Insight (EDR/XDR)** — CIS-04, CIS-07, CIS-08, CIS-10,
  CIS-13, CIS-17. Endpoint hardening, vulnerability detection, telemetry,
  malware protection, network containment, and incident response workflows.
- **Falcon Discover** — CIS-01, CIS-02, CIS-12. Asset and application inventory
  including unmanaged device discovery and lateral movement analysis.
- **Falcon Spotlight** — CIS-07. Continuous vulnerability assessment with
  prioritisation aligned to remediation SLA tracking.
- **Falcon Identity Protection** — CIS-05, CIS-06. Account takeover detection,
  conditional access policies, and identity threat hunting.
- **Falcon Complete MDR** — CIS-17, CIS-18. Managed detection, response, and
  adversary emulation to validate control efficacy.

## Fortinet

- **FortiGate NGFW & Secure SD-WAN** — CIS-04, CIS-09, CIS-12, CIS-13. Secure
  configuration enforcement, web filtering, network segmentation, and lateral
  threat containment.
- **FortiEDR & FortiClient** — CIS-04, CIS-07, CIS-10, CIS-13, CIS-17.
  Prevents malware, delivers vulnerability coverage, network quarantine, and
  orchestrated response.
- **FortiAnalyzer & FortiSIEM** — CIS-08, CIS-13, CIS-17. Central logging,
  analytics, UEBA, and incident workflows across Fortinet and third-party logs.
- **FortiAuthenticator & FortiToken** — CIS-05, CIS-06. Identity governance,
  MFA, SSO, and privileged access enforcement.
- **FortiNAC** — CIS-01, CIS-06, CIS-13. Network access control, device
  profiling, and dynamic segmentation.

## Check Point

- **Quantum Security Gateway** — CIS-04, CIS-09, CIS-12, CIS-13. Threat
  prevention, sandboxed web/email filtering, and segmented network policy.
- **Harmony Endpoint & Mobile** — CIS-04, CIS-07, CIS-10, CIS-13. Endpoint
  hardening, exploit prevention, malware defence, and network threat protection.
- **CloudGuard** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Multi-cloud asset
  posture, workload security, data protection, and compliance automation.
- **Infinity SOC** — CIS-08, CIS-13, CIS-17. Central analytics, threat intel,
  hunting, and incident response dashboards.

## IBM Security

- **QRadar SIEM & Log Insights** — CIS-08, CIS-13, CIS-17. Enterprise log
  aggregation, correlation, network analytics, and incident workflows.
- **QRadar SOAR (Resilient)** — CIS-17, CIS-18. Playbooks, automation, and
  tabletop simulation for IR maturity.
- **Guardium Data Protection** — CIS-03, CIS-04, CIS-11. Data discovery,
  encryption, activity monitoring, and recovery integration for databases.
- **MaaS360 with Watson** — CIS-01, CIS-04, CIS-05, CIS-06. Unified endpoint
  management, secure configuration, and conditional access controls.
- **Verify (Cloud Identity)** — CIS-05, CIS-06, CIS-15. Identity lifecycle,
  adaptive access, and third-party governance.

## Broadcom Symantec

- **Symantec Endpoint Security Complete** — CIS-04, CIS-07, CIS-10, CIS-13.
  Intrusion prevention, exploit mitigation, malware defence, and device control.
- **Symantec Endpoint Detection & Response** — CIS-08, CIS-13, CIS-17.
  Behavioural analytics, hunt investigations, and response automation.
- **Symantec Data Loss Prevention** — CIS-03, CIS-04. Data discovery,
  classification, and policy enforcement across endpoints, network, and cloud.
- **Symantec Web Security Service** — CIS-09, CIS-10, CIS-13. Cloud-delivered
  SWG with isolation, sandboxing, and threat intel integration.

## Trellix (McAfee + FireEye)

- **Trellix Endpoint Security** — CIS-04, CIS-07, CIS-10, CIS-13. Endpoint
  hardening, vulnerability coverage, malware prevention, and containment.
- **Trellix Helix** — CIS-08, CIS-13, CIS-17. SIEM and XDR analytics with case
  management and automated response.
- **Trellix Network Security** — CIS-12, CIS-13. Network IPS, sandboxing, and
  lateral movement detection.
- **Trellix DLP** — CIS-03, CIS-04. Data classification, blocking, and
  monitoring for regulated information.
- **Trellix Advanced Research Center** — CIS-07, CIS-17, CIS-18. Threat
  intelligence, managed detection, and breach simulation services.

## Trend Micro

- **Trend Micro Apex One** — CIS-04, CIS-07, CIS-10, CIS-13. Endpoint policy
  hardening, vulnerability shielding, malware defence, and web reputation.
- **Trend Micro Vision One XDR** — CIS-08, CIS-13, CIS-17. Cross-domain
  telemetry, analytics, and guided response.
- **Trend Micro Cloud One** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Cloud
  asset inventory, workload protection, configuration guardrails, and DLP.
- **Trend Micro Email Security** — CIS-09, CIS-10. Inbound/outbound filtering,
  spoofing controls, and malware sandboxing.

## Splunk

- **Splunk Enterprise Security** — CIS-08, CIS-13, CIS-17. SIEM analytics,
  risk-based alerting, and response workflows.
- **Splunk SOAR** — CIS-17, CIS-18. Automation, playbooks, and exercise
  orchestration to validate controls.
- **Splunk ITSI & Observability** — CIS-08, CIS-13. Service health correlation
  that enriches detection with operational telemetry.

## Rapid7

- **Rapid7 InsightIDR** — CIS-08, CIS-13, CIS-17. SIEM, UEBA, deception, and
  incident investigation tooling.
- **Rapid7 InsightVM** — CIS-01, CIS-02, CIS-07. Asset discovery, software
  inventory, and vulnerability risk scoring.
- **Rapid7 InsightAppSec** — CIS-16. DAST for web applications with policy and
  remediation tracking.
- **Rapid7 InsightConnect** — CIS-17, CIS-18. Automation runbooks, case
  management, and control validation.
- **Rapid7 InsightCloudSec** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Cloud
  posture management, data guardrails, and compliance policy enforcement.

## Tenable

- **Tenable One / Tenable.sc** — CIS-01, CIS-02, CIS-07. Continuous asset and
  vulnerability coverage across IT, OT, and cloud.
- **Tenable Vulnerability Management (IO)** — CIS-07. Internet-facing exposure
  reduction with prioritisation.
- **Tenable.cs / Tenable Cloud Security** — CIS-01, CIS-02, CIS-03, CIS-04,
  CIS-07. Cloud resource inventory, misconfiguration detection, and IaC checks.
- **Tenable.ad** — CIS-05, CIS-06. Active Directory exposure analytics and
  privileged access hardening.
- **Tenable.ot** — CIS-01, CIS-07, CIS-12. OT asset visibility, vuln scanning,
  and network segmentation support.

## Qualys

- **Qualys VMDR** — CIS-01, CIS-02, CIS-07, CIS-10. Asset inventory, software
  inventory, vulnerability assessment, and malware indicators.
- **Qualys Policy Compliance** — CIS-04. Configuration benchmarks for servers,
  databases, and middleware.
- **Qualys File Integrity Monitoring** — CIS-04, CIS-08. Change detection on
  critical systems with audit trail integration.
- **Qualys TotalCloud & Container Security** — CIS-01, CIS-02, CIS-03, CIS-04,
  CIS-07. Cloud-native inventory, IaC scanning, and workload protection.

## SentinelOne

- **SentinelOne Singularity Complete/XDR** — CIS-04, CIS-07, CIS-08, CIS-10,
  CIS-13, CIS-17. Autonomous prevention, vulnerability visibility, telemetry,
  network isolation, and guided response.
- **SentinelOne Ranger** — CIS-01, CIS-12. Passive asset discovery and lateral
  movement detection on network segments.
- **SentinelOne Vigilance MDR** — CIS-17, CIS-18. Managed detection, response,
  and adversary simulation services.

## Okta

- **Okta Workforce Identity Cloud** — CIS-05, CIS-06, CIS-15. Identity
  lifecycle, adaptive policies, and partner access controls.
- **Okta Adaptive MFA** — CIS-05, CIS-06. Strong authentication, risk-based
  challenges, and session enforcement.
- **Okta Identity Governance** — CIS-05, CIS-06. Access reviews, segregation of
  duties, and certification workflows.
- **Okta Customer Identity Cloud** — CIS-06, CIS-15. Customer access controls,
  fine-grained API security, and delegated admin boundaries.

## CyberArk

- **CyberArk Privileged Access Manager** — CIS-05, CIS-06, CIS-15. Vaulting,
  rotation, session monitoring, and third-party privileged access controls.
- **CyberArk Endpoint Privilege Manager** — CIS-04, CIS-05, CIS-06. Least
  privilege enforcement, application control, and just-in-time elevation.
- **CyberArk Secrets Manager** — CIS-03, CIS-05, CIS-06. Secure storage and
  brokering of application credentials and secrets.
- **CyberArk Identity Security Intelligence** — CIS-08, CIS-17. Analytics on
  privileged activity to support detection and response.

## SailPoint

- **SailPoint IdentityNow / IdentityIQ** — CIS-05, CIS-06, CIS-15. Identity
  governance, access certifications, and service provider oversight.
- **SailPoint Non-Employee Risk Management** — CIS-05, CIS-06, CIS-15. Vendor
  and contractor access lifecycle.
- **SailPoint SaaS Management** — CIS-01, CIS-02, CIS-03. SaaS discovery, usage
  monitoring, and data governance enforcement.

## Proofpoint

- **Proofpoint Email Security & Protection** — CIS-09, CIS-10. Advanced email
  filtering, BEC protection, and malware sandboxing.
- **Proofpoint Targeted Attack Protection** — CIS-09, CIS-13. URL defence,
  attachment sandboxing, and threat intel enrichment.
- **Proofpoint Information Protection & DLP** — CIS-03, CIS-04. Data discovery,
  classification, and insider risk controls.
- **Proofpoint Security Awareness Training** — CIS-14, CIS-17. Human risk
  reduction, phishing simulations, and behavioural metrics.

## Zscaler

- **Zscaler Internet Access (ZIA)** — CIS-03, CIS-09, CIS-10, CIS-13. Secure web
  gateway, inline DLP, malware analysis, and zero trust network access.
- **Zscaler Private Access (ZPA)** — CIS-04, CIS-06, CIS-13. Zero trust
  application access with posture-based policies and segmentation.
- **Zscaler Digital Experience (ZDX)** — CIS-13, CIS-17. Monitoring user-to-app
  performance to support detection and troubleshooting.
- **Zscaler Workload Segmentation** — CIS-01, CIS-04, CIS-13. Microsegmentation
  and runtime protection for data center workloads.

## Netskope

- **Netskope Intelligent SSE** — CIS-03, CIS-09, CIS-10, CIS-13. CASB, secure
  web gateway, inline DLP, and threat protection.
- **Netskope Private Access** — CIS-04, CIS-06, CIS-13. Zero trust access to
  private applications with adaptive policies.
- **Netskope Cloud Firewall** — CIS-12, CIS-13. Cloud-delivered firewalling and
  intrusion prevention.
- **Netskope SaaS Security Posture Management** — CIS-01, CIS-02, CIS-03,
  CIS-04. SaaS discovery, misconfiguration detection, and policy enforcement.

## Cloudflare

- **Cloudflare Zero Trust Platform** — CIS-04, CIS-06, CIS-09, CIS-13. Secure
  web gateway, ZTNA, posture checks, and network threat defence.
- **Cloudflare Area 1 Email Security** — CIS-09, CIS-10. Cloud-native email
  filtering with phishing protection and malware sandboxing.
- **Cloudflare DDoS Protection & Magic Transit** — CIS-12, CIS-13. Network
  resilience, traffic scrubbing, and routing controls.
- **Cloudflare Security Analytics** — CIS-08, CIS-13. Log aggregation,
  detection insights, and risk dashboards.

## Darktrace

- **Darktrace Enterprise Immune System** — CIS-08, CIS-13, CIS-17. AI-driven
  anomaly detection across network, cloud, and endpoint telemetry.
- **Darktrace Antigena Email** — CIS-09, CIS-10. Autonomous phishing and
  payload disruption.
- **Darktrace PREVENT** — CIS-07, CIS-18. Attack surface management, attack
  path modelling, and control validation.
- **Darktrace Managed Detection & Response** — CIS-17, CIS-18. 24/7 monitoring,
  response, and scenario testing.

## Elastic

- **Elastic Security (SIEM & XDR)** — CIS-08, CIS-13, CIS-17. Log analytics,
  detection engineering, and response workflows on the Elastic Stack.
- **Elastic Endpoint Security** — CIS-04, CIS-07, CIS-10. Endpoint hardening,
  exploit prevention, and malware blocking.
- **Elastic Agent & Fleet** — CIS-01, CIS-02, CIS-08. Unified data collection,
  software inventory, and audit log shipping.

## LogRhythm

- **LogRhythm SIEM** — CIS-08, CIS-13, CIS-17. Log aggregation, UEBA, and SOC
  workflow orchestration.
- **LogRhythm NDR** — CIS-12, CIS-13. Network traffic analytics and lateral
  movement detection.
- **LogRhythm Axon SOAR** — CIS-17, CIS-18. Automation, case management, and
  purple-team exercises.

## Exabeam

- **Exabeam Fusion SIEM** — CIS-08, CIS-13, CIS-17. SaaS SIEM with analytics,
  UEBA, and investigation automation.
- **Exabeam Security Operations Platform** — CIS-08, CIS-13, CIS-17, CIS-18.
  Detections, case management, threat hunting, and control validation.

## Arctic Wolf

- **Arctic Wolf Managed Detection & Response** — CIS-07, CIS-08, CIS-13,
  CIS-17. 24/7 monitoring, vulnerability context, and guided response.
- **Arctic Wolf Managed Risk** — CIS-01, CIS-02, CIS-07. Asset inventory,
  attack surface management, and remediation coaching.
- **Arctic Wolf Managed Security Awareness** — CIS-14. Human risk training,
  phishing simulations, and metrics.
- **Arctic Wolf Incident Response** — CIS-17, CIS-18. Preparedness, tabletop,
  and crisis response services.

## VMware Carbon Black

- **Carbon Black Cloud Endpoint** — CIS-04, CIS-07, CIS-10, CIS-13. Next-gen AV,
  behavioural prevention, vulnerability context, and network control.
- **Carbon Black Cloud Audit & Remediation** — CIS-04, CIS-08. Endpoint
  configuration assessment and live query with audit integration.
- **Carbon Black Container Security** — CIS-01, CIS-02, CIS-04, CIS-07. Image
  scanning, runtime protection, and policy enforcement for Kubernetes.

## Sophos

- **Sophos Intercept X** — CIS-04, CIS-07, CIS-10, CIS-13. Exploit mitigation,
  threat hunting, malware defence, and network blocking.
- **Sophos XDR** — CIS-08, CIS-13, CIS-17. Cross-product telemetry, analytics,
  and incident response guidance.
- **Sophos Firewall & ZTNA** — CIS-04, CIS-09, CIS-12, CIS-13. Secure gateway,
  segmentation, and application access control.
- **Sophos Managed Detection & Response** — CIS-17, CIS-18. Managed SOC with
  response and control validation exercises.

## Ivanti

- **Ivanti Neurons for Patch Management** — CIS-04, CIS-07. Automated patching,
  configuration baselining, and remediation SLAs.
- **Ivanti Neurons for RBVM** — CIS-07. Risk-based vulnerability prioritisation
  across hybrid environments.
- **Ivanti Secure Access (Pulse Secure)** — CIS-04, CIS-06, CIS-13. Zero trust
  VPN, device compliance checks, and segmentation.
- **Ivanti Neurons for Discovery** — CIS-01, CIS-02. Continuous asset and
  software inventory.

## Imperva

- **Imperva Web Application Firewall (WAAP)** — CIS-13, CIS-16. Runtime
  protection for web apps and APIs, shielding against OWASP and DDoS threats.
- **Imperva Data Security Fabric** — CIS-03, CIS-04, CIS-11. Data discovery,
  masking, encryption, and recovery planning.
- **Imperva Runtime Application Self-Protection (RASP)** — CIS-16. In-app
  control flow protection and exploit prevention.
- **Imperva DDoS Protection** — CIS-12, CIS-13. Network and application-layer
  resilience services.

## F5

- **F5 BIG-IP Advanced WAF** — CIS-13, CIS-16. Application-layer firewalling,
  bot defence, and API protection.
- **F5 Distributed Cloud WAAP** — CIS-13, CIS-16. SaaS WAF, API security, and
  DDoS protection across multi-cloud.
- **F5 NGINX App Protect** — CIS-13, CIS-16. Lightweight WAF and security
  policies embedded in application delivery.
- **F5 BIG-IP Access Policy Manager** — CIS-04, CIS-06, CIS-13. Secure remote
  access, SSO, and network segmentation.

## ServiceNow

- **ServiceNow Security Operations** — CIS-07, CIS-08, CIS-17. Vulnerability
  response, incident response, and automation workflows with CMDB context.
- **ServiceNow IRM / GRC** — CIS-03, CIS-04, CIS-11, CIS-15. Control monitoring,
  policy attestation, and service provider risk management.
- **ServiceNow Operational Technology Manager** — CIS-01, CIS-12. OT asset
  inventory and network segmentation planning.

## RSA

- **RSA NetWitness Platform** — CIS-08, CIS-13, CIS-17. SIEM, NDR, and endpoint
  detection with unified investigations.
- **RSA SecurID** — CIS-05, CIS-06. MFA, risk-based authentication, and
  privileged session controls.
- **RSA Archer** — CIS-03, CIS-04, CIS-11, CIS-15. Risk management, policy
  governance, and third-party oversight.

## Bitdefender

- **Bitdefender GravityZone Enterprise** — CIS-04, CIS-07, CIS-10, CIS-13.
  Endpoint hardening, risk analytics, malware defence, and network control.
- **Bitdefender GravityZone XDR** — CIS-08, CIS-13, CIS-17. Cross-domain
  telemetry, detections, and guided response.
- **Bitdefender Managed Detection & Response** — CIS-17, CIS-18. 24/7 SOC with
  threat hunting and validation.

## Veeam

- **Veeam Backup & Replication** — CIS-03, CIS-11. Immutable backups,
  replication, and recovery orchestration.
- **Veeam ONE** — CIS-07, CIS-11. Monitoring, capacity planning, and anomaly
  detection supporting recovery readiness.
- **Veeam Data Platform** — CIS-03, CIS-11, CIS-17. Ransomware detection,
  incident recovery workflows, and data compliance reporting.

## Rubrik

- **Rubrik Security Cloud** — CIS-03, CIS-11, CIS-17. Immutable backups, data
  classification, and incident response automation.
- **Rubrik Zero Trust Data Protection** — CIS-03, CIS-04, CIS-11. Application
  consistent backups, policy enforcement, and recovery validation.
- **Rubrik Cyber Recovery** — CIS-11, CIS-17. Isolated recovery environments,
  forensic validation, and response playbooks.

## Cohesity

- **Cohesity DataProtect** — CIS-03, CIS-11. Snapshot-based backups, retention,
  and ransomware resiliency.
- **Cohesity Threat Defense** — CIS-07, CIS-10, CIS-17. Malware detection,
  anomaly detection, and incident response integration.
- **Cohesity DataHawk** — CIS-03, CIS-11. Data classification, DLP, and recovery
  readiness scoring.

## KnowBe4

- **KnowBe4 Security Awareness Training** — CIS-14. Role-based curricula,
  phishing simulations, and behavioural metrics.
- **KnowBe4 PhishER** — CIS-09, CIS-17. Triage automation for reported
  phishing, feeding incident response.
- **KnowBe4 Compliance Plus** — CIS-03, CIS-14. Policy training for data
  handling and regulatory topics.

## Recorded Future

- **Recorded Future Intelligence Platform** — CIS-07, CIS-13, CIS-17. External
  threat intelligence, vulnerability prioritisation, and response enrichment.
- **Recorded Future Third-Party Intelligence** — CIS-15. Continuous monitoring
  of suppliers and service providers for risk indicators.
- **Recorded Future Attack Surface Intelligence** — CIS-01, CIS-02, CIS-07.
  External asset discovery and exposure management.

## Mandiant (Google Cloud)

- **Mandiant Advantage Threat Intelligence** — CIS-07, CIS-13, CIS-17. Global
  adversary insights, threat hunting support, and incident enrichment.
- **Mandiant Managed Defense** — CIS-07, CIS-08, CIS-13, CIS-17. Managed SOC,
  telemetry analysis, and crisis response.
- **Mandiant Incident Response Services** — CIS-17, CIS-18. Readiness
  assessments, tabletop exercises, and breach response.

## Google Cloud Security

- **Google Chronicle Security Operations** — CIS-08, CIS-13, CIS-17. Cloud SIEM,
  hyper-scale log retention, and detection engineering.
- **Google Cloud Armor** — CIS-12, CIS-13, CIS-16. DDoS mitigation, WAF, and
  API protection for Google Cloud workloads.
- **Google BeyondCorp Enterprise** — CIS-04, CIS-06, CIS-13. Zero trust access,
  device posture checks, and application segmentation.
- **Google Security Command Center** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07.
  Cloud posture management, threat detection, and data security insights.

## Amazon Web Services Security

- **AWS Security Hub** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Aggregates
  findings from AWS services against CIS benchmarks and best practices.
- **Amazon GuardDuty** — CIS-07, CIS-08, CIS-13. Threat detection for AWS
  accounts, workloads, and data sources.
- **AWS Identity and Access Management (IAM) & IAM Identity Center** — CIS-05,
  CIS-06, CIS-15. Fine-grained access control, privilege management, and
  partner delegation.
- **AWS Backup** — CIS-03, CIS-11. Centralised policy-based backups and
  recovery orchestration.
- **AWS Shield Advanced** — CIS-12, CIS-13. Managed DDoS protection and network
  resilience.

## Oracle Cloud Security

- **Oracle Cloud Guard** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Cloud asset
  posture monitoring, security recipes, and remediation.
- **Oracle Identity Security Operations** — CIS-05, CIS-06. Identity lifecycle
  and adaptive access controls.
- **Oracle Data Safe** — CIS-03, CIS-04, CIS-11. Database security, auditing,
  and recovery validation.
- **Oracle Cloud Infrastructure WAF** — CIS-13, CIS-16. Application-layer
  protection and DDoS controls.

## Dell Technologies (Secureworks & Data Protection)

- **Secureworks Taegis XDR** — CIS-07, CIS-08, CIS-13, CIS-17. Managed XDR with
  threat hunting and incident response.
- **Secureworks Incident Response** — CIS-17, CIS-18. Preparedness, tabletop,
  and crisis services.
- **Dell PowerProtect Data Manager** — CIS-03, CIS-11. Backup immutability,
  recovery orchestration, and cyber vaulting.

## HPE & Aruba Networking

- **Aruba ClearPass Policy Manager** — CIS-01, CIS-06, CIS-13. Network access
  control, device profiling, and segmentation.
- **Aruba ESP (Edge Services Platform)** — CIS-01, CIS-12, CIS-13. Unified
  network visibility, microsegmentation, and threat containment.
- **HPE GreenLake Backup and Recovery** — CIS-03, CIS-11. SaaS backup for hybrid
  workloads supporting recovery SLAs.

## ExtraHop

- **ExtraHop Reveal(x)** — CIS-12, CIS-13, CIS-17. NDR with encrypted traffic
  analytics, lateral movement detection, and incident investigations.
- **ExtraHop Threat Briefings** — CIS-07, CIS-13. Threat intelligence and
  detection updates aligned to emerging campaigns.

## Illumio

- **Illumio Core** — CIS-04, CIS-12, CIS-13. Microsegmentation for data
  centers, reducing lateral movement.
- **Illumio Edge** — CIS-04, CIS-13. Endpoint segmentation and policy control.
- **Illumio CloudSecure** — CIS-01, CIS-02, CIS-13. Cloud asset visibility and
  segmentation policies.

## FireMon

- **FireMon Security Manager** — CIS-04, CIS-12, CIS-13. Network policy
  automation, compliance, and segmentation oversight.
- **FireMon Lumeta** — CIS-01, CIS-12, CIS-13. Continuous network discovery and
  leak-path analysis.

## Skybox Security

- **Skybox Security Posture Management** — CIS-01, CIS-02, CIS-04, CIS-07,
  CIS-12, CIS-13. Hybrid network modelling, vulnerability prioritisation, and
  segmentation validation.

## AttackIQ

- **AttackIQ Enterprise Platform** — CIS-17, CIS-18. Continuous security
  control validation with MITRE ATT&CK-aligned scenarios.
- **AttackIQ Ready! Program** — CIS-14, CIS-17, CIS-18. Training and tabletop
  exercises paired with breach simulations.

## SafeBreach

- **SafeBreach BAS Platform** — CIS-17, CIS-18. Breach and attack simulation to
  validate control effectiveness and incident readiness.
- **SafeBreach Recommendations Module** — CIS-07, CIS-17. Maps failed tests to
  remediation actions and vulnerability priorities.

## Pentera

- **Pentera Automated Security Validation** — CIS-17, CIS-18. Automated
  penetration testing, exploit path analysis, and remediation guidance.

## Qualys TruRisk Alliance Partners

- **Qualys + ServiceNow Integration** — CIS-07, CIS-17. Drives remediation
  workflows via CMDB synchronisation and automation.
- **Qualys + Splunk Apps** — CIS-08, CIS-13. Exposes vulnerability telemetry
  within SIEM detections.

## Veracode

- **Veracode Application Security Platform** — CIS-16. SAST, DAST, SCA, and
  manual pen testing services for application security.
- **Veracode Security Labs** — CIS-14, CIS-16, CIS-18. Developer training and
  secure coding exercises.

## Synopsys Software Integrity

- **Synopsys Coverity & Black Duck** — CIS-16. Static analysis and software
  composition analysis for secure development.
- **Synopsys Seeker** — CIS-16. Interactive application security testing (IAST)
  for runtime vulnerability detection.
- **Synopsys TCM** — CIS-18. Penetration testing and red team services.

## GitLab & GitHub Security

- **GitLab Ultimate Security** — CIS-16. Integrated SAST, DAST, SCA, and
  dependency scanning in the CI/CD pipeline.
- **GitHub Advanced Security** — CIS-16. Code scanning, secret scanning, and
  dependency alerts tied to repositories.

## Atlassian Security Operations

- **Atlassian Access** — CIS-05, CIS-06, CIS-15. SSO, MFA, and organisational
  controls for Atlassian cloud tenants.
- **Atlassian Opsgenie + Jira Service Management** — CIS-17. Incident response,
  on-call orchestration, and post-incident reviews.

## Wiz

- **Wiz Cloud Security Platform** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07.
  Cloud-native application protection with agentless scanning, risk graphing,
  and data exposure detection.
- **Wiz Threat Center** — CIS-07, CIS-13. Curated threat detections correlated
  with cloud exposures.

## Lacework

- **Lacework Polygraph** — CIS-01, CIS-02, CIS-03, CIS-04, CIS-07. Cloud
  inventory, behavioural anomaly detection, and workload protection.
- **Lacework Build Time Security** — CIS-04, CIS-07, CIS-16. Infrastructure as
  code scanning and container image analysis.

## Snyk

- **Snyk Application Security Platform** — CIS-16. Developer-focused scanning
  for code, open source, containers, and IaC.
- **Snyk Learn** — CIS-14, CIS-16. Secure coding education with interactive
  lessons.

## Red Canary

- **Red Canary MDR** — CIS-07, CIS-08, CIS-13, CIS-17. Managed detection,
  telemetry enrichment, and guided response.
- **Red Canary Atomic Red Team** — CIS-17, CIS-18. Open-source adversary
  emulation for control validation and training.

## Expel

- **Expel MDR** — CIS-07, CIS-08, CIS-13, CIS-17. Vendor-agnostic managed
  detection and response with playbook automation.
- **Expel IR Preparedness** — CIS-17, CIS-18. Tabletop exercises, runbook
  development, and crisis support.

## BlueVoyant

- **BlueVoyant MDR** — CIS-07, CIS-08, CIS-13, CIS-17. SOC-as-a-service with
  threat intel-driven detection.
- **BlueVoyant Supply Chain Defence** — CIS-15. Continuous monitoring and
  scoring of service provider risk.

## ReliaQuest

- **ReliaQuest GreyMatter** — CIS-07, CIS-08, CIS-13, CIS-17. Open XDR platform
  aggregating telemetry, detections, and response.
- **ReliaQuest GreyMatter Digital Risk Protection** — CIS-07, CIS-13, CIS-15.
  External threat monitoring and partner risk insights.

## Hunters

- **Hunters SOC Platform** — CIS-08, CIS-13, CIS-17. Autonomous detection,
  investigation, and incident response orchestration.

## Devo Technology

- **Devo Security Operations** — CIS-08, CIS-13, CIS-17. Cloud-native logging,
  analytics, and automation.

## Securonix

- **Securonix Unified Defense SIEM** — CIS-08, CIS-13, CIS-17. UEBA-driven SIEM
  with threat content and response workflows.
- **Securonix NDR** — CIS-12, CIS-13. Network detection leveraging UEBA and
  threat intel.

## LogPoint

- **LogPoint SIEM & UEBA** — CIS-08, CIS-13, CIS-17. European-focused SIEM with
  analytics and compliance reporting.
- **LogPoint SOAR** — CIS-17, CIS-18. Automation playbooks and incident
  orchestration.

## Graylog

- **Graylog Security** — CIS-08, CIS-13, CIS-17. Log management, anomaly
  detection, and incident workflows.

## ManageEngine

- **ManageEngine ADManager Plus** — CIS-05, CIS-06. AD account lifecycle, audit,
  and delegation controls.
- **ManageEngine Log360** — CIS-08, CIS-13, CIS-17. SIEM, UEBA, and incident
  workflows for hybrid environments.
- **ManageEngine Endpoint Central** — CIS-04, CIS-07, CIS-10. Configuration
  management, patching, and malware integration.

## One Identity

- **One Identity Manager** — CIS-05, CIS-06, CIS-15. Identity governance,
  access certification, and partner oversight.
- **One Identity Safeguard** — CIS-05, CIS-06. Privileged access management with
  session monitoring and analytics.

## Thales

- **Thales CipherTrust Data Security** — CIS-03, CIS-04. Encryption, key
  management, and data discovery across hybrid environments.
- **Thales SafeNet Trusted Access** — CIS-05, CIS-06. MFA, adaptive access, and
  federation.
- **Thales Luna Hardware Security Modules** — CIS-03, CIS-04. Protects keys and
  supports secure cryptographic operations.

## Entrust

- **Entrust Identity as a Service** — CIS-05, CIS-06. Workforce and customer MFA
  and adaptive policies.
- **Entrust PKI & Certificate Services** — CIS-03, CIS-04. Certificate lifecycle
  management supporting secure configurations.
- **Entrust DataControl** — CIS-03, CIS-04. Encryption and key management for
  hybrid cloud workloads.

## Varonis

- **Varonis Data Security Platform** — CIS-03, CIS-04, CIS-05. Data discovery,
  least-privilege automation, and access monitoring for file stores.
- **Varonis DatAlert** — CIS-08, CIS-13, CIS-17. Behaviour analytics on data
  access and insider threats.

## Netwrix

- **Netwrix Auditor** — CIS-05, CIS-06, CIS-08. Privileged access auditing and
  change tracking across AD, O365, and infrastructure.
- **Netwrix StealthAUDIT & StealthDEFEND** — CIS-03, CIS-04, CIS-05. Data access
  governance, classification, and anomaly detection.

## Gigamon

- **Gigamon Deep Observability Pipeline** — CIS-08, CIS-13. Network traffic
  visibility feeding SIEM, NDR, and security analytics.
- **Gigamon ThreatINSIGHT** — CIS-12, CIS-13. SaaS NDR with guided response.

## Nozomi Networks

- **Nozomi Vantage** — CIS-01, CIS-07, CIS-12. OT/IoT asset inventory, anomaly
  detection, and network segmentation advisory.
- **Nozomi Guardian** — CIS-07, CIS-12, CIS-13. Passive monitoring, vulnerability
  insights, and threat detection for operational networks.

## Dragos

- **Dragos Platform** — CIS-01, CIS-07, CIS-12, CIS-13. OT asset visibility,
  threat detection, and guided response playbooks.
- **Dragos WorldView Threat Intelligence** — CIS-07, CIS-13. OT-focused
  intelligence and risk insights.

## Armis

- **Armis Asset Intelligence Platform** — CIS-01, CIS-02, CIS-12. Agentless
  discovery of IT, OT, IoT, and medical devices with risk scoring.
- **Armis Centrix for Vulnerability Prioritization** — CIS-07. Exposure ranking
  leveraging device context.

## Claroty

- **Claroty xDome** — CIS-01, CIS-07, CIS-12. OT/IoT asset inventory, risk
  assessment, and segmentation policies.
- **Claroty Secure Remote Access** — CIS-06, CIS-13. Controlled vendor access
  into industrial networks.

## Illusive

- **Illusive Attack Surface Manager** — CIS-01, CIS-07. Privileged identity
  discovery and exposure reduction.
- **Illusive Active Defense** — CIS-13, CIS-17, CIS-18. Deception-based
  controls and incident acceleration.

## HCLTech (BigFix & AppScan)

- **HCL BigFix** — CIS-01, CIS-02, CIS-04, CIS-07. Unified endpoint management,
  configuration baselines, and patch automation.
- **HCL AppScan** — CIS-16. Application security testing across SAST, DAST, and
  IAST modalities.

## Absolute Software

- **Absolute Secure Endpoint** — CIS-01, CIS-04, CIS-07, CIS-10. Endpoint
  persistence, configuration enforcement, and malware resistance.
- **Absolute Insights for Endpoints** — CIS-08, CIS-13. Telemetry and anomaly
  detection for device fleets.

## Tanium

- **Tanium Platform** — CIS-01, CIS-02, CIS-04, CIS-07, CIS-08. Real-time asset
  inventory, configuration management, patching, and telemetry collection.
- **Tanium Threat Response** — CIS-07, CIS-13, CIS-17. Endpoint detection,
  hunting, and rapid remediation.
- **Tanium Reveal** — CIS-03, CIS-04. Data discovery and policy enforcement on
  endpoints.

## Automox

- **Automox Cloud-Native Patch Management** — CIS-04, CIS-07. Automated patch
  orchestration across heterogeneous fleets.
- **Automox Worklets** — CIS-04. Custom configuration and hardening scripts.

## BigID

- **BigID Data Intelligence Platform** — CIS-03, CIS-04, CIS-11. Data discovery,
  classification, retention governance, and privacy controls.
- **BigID Data Risk Mitigation** — CIS-03, CIS-04. Sensitive data remediation,
  minimisation, and policy enforcement.

## Varonis DatAdvantage Cloud

- **DatAdvantage Cloud** — CIS-03, CIS-04, CIS-05. Least-privilege automation,
  entitlement analysis, and activity monitoring for SaaS.
