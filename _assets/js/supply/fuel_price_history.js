/**
 * Pestaña "Histórico" de Abastos › Precios de combustibles.
 *
 * Consulta /supply/fuel_price_history (POST codgas[], from=YYYY-MM, to=YYYY-MM)
 * y pinta, por producto: gráfica escalonada por estación, resumen por estación
 * (que también funciona como leyenda) y el detalle de cambios.
 */
(function ($) {
	"use strict";

	const ENDPOINT = "/supply/fuel_price_history";
	const MAX_PLOTTED = 8;

	// Paleta categórica validada (orden fijo, nunca se cicla). Ver skill dataviz.
	const PALETTE = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100", "#e87ba4", "#008300", "#6250d6", "#e34948"];

	const FAMILIES = [
		{ key: "regular", label: "Regular", color: "var(--bs-success, #1cbb8c)" },
		{ key: "premium", label: "Premium", color: "var(--bs-primary, #004EA2)" },
		{ key: "diesel", label: "Diesel", color: "#212529" },
	];

	const money = new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN", minimumFractionDigits: 2 });
	const signed = new Intl.NumberFormat("es-MX", { minimumFractionDigits: 2, maximumFractionDigits: 2, signDisplay: "exceptZero" });
	const dayFmt = new Intl.DateTimeFormat("es-MX", { day: "2-digit", month: "short", year: "numeric" });

	const state = {
		data: null,
		family: "regular",
		plotted: [], // codgas en orden de activación
		slots: new Map(), // codgas -> índice de color (sigue a la estación, no a su posición)
		xhr: null,
	};

	let chart = null;
	let table = null;

	// ── utilidades ───────────────────────────────────────────────
	const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);

	function toDate(fecha, hora) {
		const [y, m, d] = fecha.split("-").map(Number);
		const [hh, mm] = (hora || "00:00").split(":").map(Number);
		return new Date(y, m - 1, d, hh, mm);
	}

	function monthValue(date) {
		return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
	}

	function fmtDay(fecha) {
		return dayFmt.format(toDate(fecha)).replace(/\./g, "");
	}

	function deltaHtml(value) {
		if (value === null || value === undefined) return '<span class="fp-flat">—</span>';
		if (Math.abs(value) < 0.005) return '<span class="fp-flat">sin cambio</span>';
		const up = value > 0;
		return `<span class="${up ? "fp-up" : "fp-down"} fp-num"><i class="fa-solid fa-arrow-${up ? "up" : "down"} me-1" aria-hidden="true"></i>${signed.format(value)}</span>`;
	}

	function colorOf(codgas) {
		return PALETTE[state.slots.get(codgas)];
	}

	// ── filtros ──────────────────────────────────────────────────
	const $form = $("#fp-history-form");
	const $stations = $("#fp-h-stations");
	const $from = $("#fp-h-from");
	const $to = $("#fp-h-to");
	const $error = $("#fp-h-error");
	const $submit = $("#fp-h-submit");

	function applyPreset(months) {
		const now = new Date();
		const start = new Date(now.getFullYear(), now.getMonth() - (months - 1), 1);
		$from.val(monthValue(start));
		$to.val(monthValue(now));
		$(".fp-presets .btn").removeClass("active").attr("aria-pressed", "false");
		$(`.fp-presets .btn[data-months="${months}"]`).addClass("active").attr("aria-pressed", "true");
		$error.text("");
	}

	$(".fp-presets .btn").on("click", function () {
		applyPreset(Number($(this).data("months")));
	});

	$from.add($to).on("input change", function () {
		$(".fp-presets .btn").removeClass("active").attr("aria-pressed", "false");
		$error.text("");
	});
	$stations.on("changed.bs.select change", () => $error.text(""));

	function validate() {
		const codgas = $stations.val() || [];
		const from = $from.val();
		const to = $to.val();
		if (!codgas.length) return "Selecciona al menos una estación.";
		if (!from || !to) return "Indica el mes inicial y el mes final.";
		if (from > to) return "El mes «Desde» debe ser anterior o igual al mes «Hasta».";
		const [fy, fm] = from.split("-").map(Number);
		const [ty, tm] = to.split("-").map(Number);
		if ((ty - fy) * 12 + (tm - fm) + 1 > 48) return "El periodo puede abarcar como máximo 48 meses.";
		return null;
	}

	$form.on("submit", function (e) {
		e.preventDefault();
		const problem = validate();
		if (problem) {
			$error.text(problem);
			return;
		}
		load({ codgas: $stations.val(), from: $from.val(), to: $to.val() });
	});

	// ── estados de la vista ──────────────────────────────────────
	function show(view) {
		$("#fp-h-empty").toggleClass("d-none", view !== "empty" && view !== "error" && view !== "nodata");
		$("#fp-h-loading").toggleClass("d-none", view !== "loading");
		$("#fp-h-content").toggleClass("d-none", view !== "content");
	}

	function setEmpty(icon, title, text) {
		const $empty = $("#fp-h-empty");
		$empty.find("i").attr("class", `fa-solid ${icon} d-block`);
		$empty.find("h6").text(title);
		$empty.find("p").text(text);
	}

	function load(params) {
		if (state.xhr) state.xhr.abort();
		show("loading");
		$submit.prop("disabled", true).html('<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Consultando…');

		state.xhr = $.ajax({ url: ENDPOINT, type: "POST", dataType: "json", data: params })
			.done(function (res) {
				if (!res || !Array.isArray(res.rows)) {
					setEmpty("fa-triangle-exclamation", "No se pudo cargar el histórico", "La respuesta del servidor no es válida. Vuelve a intentarlo; si persiste, recarga la página (tu sesión pudo haber expirado).");
					show("error");
					return;
				}
				if (!res.rows.length) {
					setEmpty("fa-calendar-xmark", "Sin precios registrados", "Las estaciones seleccionadas no tienen precios registrados en ese periodo ni antes de él. Prueba con otro rango o con otras estaciones.");
					show("nodata");
					return;
				}
				receive(res);
			})
			.fail(function (xhr, status) {
				if (status === "abort") return;
				const msg = xhr.responseJSON && xhr.responseJSON.error;
				if (msg) {
					$error.text(msg);
					setEmpty("fa-chart-line", "Consulta el histórico de precios", "Corrige los filtros marcados y vuelve a consultar.");
				} else {
					setEmpty("fa-triangle-exclamation", "No se pudo cargar el histórico", "Hubo un problema al consultar el servidor. Vuelve a intentarlo; si persiste, recarga la página (tu sesión pudo haber expirado).");
				}
				show("error");
			})
			.always(function () {
				$submit.prop("disabled", false).html('<i class="fa-solid fa-magnifying-glass me-1"></i> Consultar histórico');
			});
	}

	// ── datos ────────────────────────────────────────────────────
	function receive(res) {
		res.rows.forEach((r) => (r.date = toDate(r.fecha, r.hora)));
		state.data = res;

		// Estaciones a graficar: las primeras 8 de la selección que tengan datos.
		state.plotted = [];
		state.slots.clear();
		res.estaciones.forEach((s) => {
			if (state.plotted.length < MAX_PLOTTED && res.rows.some((r) => r.codgas === s.codgas)) plot(s.codgas);
		});

		const withData = FAMILIES.find((f) => res.rows.some((r) => r.familia === f.key));
		state.family = res.rows.some((r) => r.familia === state.family) ? state.family : withData.key;

		$("#fp-h-caption").text(`Del ${fmtDay(res.desde)} al ${fmtDay(res.hasta)} · ${res.estaciones.length} ${res.estaciones.length === 1 ? "estación" : "estaciones"}`);

		show("content");
		renderProducts();
		renderFamily();
		fillTable();
	}

	function plot(codgas) {
		const used = new Set(state.slots.values());
		const slot = PALETTE.findIndex((_, i) => !used.has(i));
		state.slots.set(codgas, slot);
		state.plotted.push(codgas);
	}

	function unplot(codgas) {
		state.slots.delete(codgas);
		state.plotted = state.plotted.filter((c) => c !== codgas);
	}

	function familyRows(codgas) {
		return state.data.rows.filter((r) => r.familia === state.family && r.codgas === codgas).sort((a, b) => a.ts - b.ts);
	}

	// ── selector de producto ─────────────────────────────────────
	function renderProducts() {
		const html = FAMILIES.map((f) => {
			const rows = state.data.rows.filter((r) => r.familia === f.key);
			const changes = rows.filter((r) => !r.inicial).length;
			return `<button type="button" data-family="${f.key}" aria-pressed="${f.key === state.family}" ${rows.length ? "" : "disabled"}
						title="${changes} ${changes === 1 ? "cambio" : "cambios"} en el periodo">
						<span class="fp-dot" style="background:${f.color}" aria-hidden="true"></span>${f.label}
						<span class="fp-count">${changes}</span>
					</button>`;
		}).join("");
		$("#fp-h-products").html(html);
	}

	$("#fp-h-products").on("click", "button[data-family]", function () {
		state.family = $(this).data("family");
		$("#fp-h-products button").attr("aria-pressed", "false");
		$(this).attr("aria-pressed", "true");
		renderFamily();
		if (table) table.column(6).search(`^${state.family}$`, true, false).draw();
	});

	function renderFamily() {
		renderSummary();
		renderChart();
	}

	// ── resumen por estación (funciona como leyenda) ─────────────
	function renderSummary() {
		const html = state.data.estaciones
			.map((s) => {
				const rows = familyRows(s.codgas);
				if (!rows.length) {
					return `<tr class="is-off" aria-disabled="true">
								<td><span class="fp-swatch" aria-hidden="true"></span>${esc(s.nombre)}</td>
								<td colspan="6" class="text-end">Sin registros de este producto</td>
							</tr>`;
				}
				const prices = rows.map((r) => r.precio);
				const first = rows[0];
				const last = rows[rows.length - 1];
				const changes = rows.filter((r) => !r.inicial).length;
				const on = state.slots.has(s.codgas);
				return `<tr data-codgas="${s.codgas}" class="${on ? "" : "is-off"}" tabindex="0" role="switch" aria-checked="${on}"
							title="${on ? "Ocultar de la gráfica" : "Mostrar en la gráfica"}">
							<td><span class="fp-swatch" style="background:${on ? colorOf(s.codgas) : "transparent"}" aria-hidden="true"></span>${esc(s.nombre)}</td>
							<td class="text-end fp-num">${money.format(first.precio)}${first.inicial ? "" : '<span class="fp-tag fp-tag-start" title="No hay precio previo al periodo; se toma el primer cambio">1er cambio</span>'}</td>
							<td class="text-end fp-num">${money.format(last.precio)}${last.programado ? '<span class="fp-tag fp-tag-future">programado</span>' : ""}</td>
							<td class="text-end">${changes ? deltaHtml(last.precio - first.precio) : '<span class="fp-flat">sin cambio</span>'}</td>
							<td class="text-end fp-num">${changes}</td>
							<td class="text-end fp-num">${money.format(Math.min(...prices))}</td>
							<td class="text-end fp-num">${money.format(Math.max(...prices))}</td>
						</tr>`;
			})
			.join("");
		$("#fp-h-summary").html(html);

		const total = state.data.estaciones.length;
		$("#fp-h-plot-hint").text(
			total > 1 ? `Haz clic en una estación para mostrarla u ocultarla en la gráfica${total > MAX_PLOTTED ? ` (máximo ${MAX_PLOTTED} a la vez)` : ""}.` : ""
		);
	}

	function toggleStation(codgas) {
		if (state.slots.has(codgas)) {
			unplot(codgas);
		} else {
			if (state.plotted.length >= MAX_PLOTTED) {
				toastr.info(`La gráfica muestra hasta ${MAX_PLOTTED} estaciones a la vez para que las líneas se distingan. Oculta una para agregar otra.`);
				return;
			}
			plot(codgas);
		}
		renderFamily();
	}

	$("#fp-h-summary")
		.on("click", "tr[data-codgas]", function () {
			toggleStation(Number($(this).data("codgas")));
		})
		.on("keydown", "tr[data-codgas]", function (e) {
			if (e.key === "Enter" || e.key === " ") {
				e.preventDefault();
				const codgas = Number($(this).data("codgas"));
				toggleStation(codgas);
				$(`#fp-h-summary tr[data-codgas="${codgas}"]`).trigger("focus");
			}
		});

	// ── gráfica ──────────────────────────────────────────────────
	function renderChart() {
		const el = document.getElementById("fp-chart");
		if (!chart) {
			chart = echarts.init(el, null, { renderer: "svg" });
			window.addEventListener("resize", () => chart && chart.resize());
		}

		const start = toDate(state.data.desde, "00:00");
		const periodEnd = toDate(state.data.hasta, "23:59");
		const now = new Date();

		const series = state.plotted
			.map((codgas) => {
				const rows = familyRows(codgas);
				if (!rows.length) return null;
				const name = state.data.estaciones.find((s) => s.codgas === codgas).nombre;
				const points = rows.map((r) => ({
					value: [r.inicial ? start : r.date, r.precio],
					row: r,
				}));
				// Prolonga el último precio hasta hoy (o el fin del periodo) para que el escalón se lea completo.
				const last = rows[rows.length - 1];
				const end = new Date(Math.min(periodEnd, Math.max(now, last.date)));
				if (end > points[points.length - 1].value[0]) {
					points.push({ value: [end, last.precio], symbol: "none", tail: true });
				}
				return {
					name,
					type: "line",
					step: "end",
					data: points,
					color: colorOf(codgas),
					lineStyle: { width: 2, cap: "round", join: "round" },
					symbol: "circle",
					symbolSize: 8,
					itemStyle: { borderColor: "#fff", borderWidth: 2 },
					emphasis: { focus: "series", scale: 1.4 },
					endLabel: state.plotted.length <= 4 ? { show: true, formatter: name, color: "#52514e", fontSize: 11, distance: 6 } : { show: false },
				};
			})
			.filter(Boolean);

		// Etiquetas al final de línea: las estaciones que terminan en (casi) el mismo
		// precio comparten una sola etiqueta para que no se encimen.
		if (state.plotted.length <= 4 && series.length) {
			const lastY = (s) => s.data[s.data.length - 1].value[1];
			const ys = series.flatMap((s) => s.data.map((d) => d.value[1]));
			const gap = Math.max(0.01, (Math.max(...ys) - Math.min(...ys)) * 0.06);
			const ordered = [...series].sort((a, b) => lastY(a) - lastY(b));
			let cluster = [];
			const flush = () => {
				if (!cluster.length) return;
				cluster.forEach((s, i) => {
					s.endLabel = i === 0 ? { ...s.endLabel, formatter: cluster.map((c) => c.name).join(" · ") } : { show: false };
				});
				cluster = [];
			};
			ordered.forEach((s) => {
				if (cluster.length && lastY(s) - lastY(cluster[cluster.length - 1]) > gap) flush();
				cluster.push(s);
			});
			flush();
		}
		const labelWidth = Math.max(0, ...series.map((s) => (s.endLabel.show === false ? 0 : String(s.endLabel.formatter).length * 6.5)));

		// El eje termina hoy si el periodo sigue en curso (o en el último precio programado).
		const lastPoint = Math.max(0, ...series.flatMap((s) => s.data.map((d) => +d.value[0])));
		const axisEnd = new Date(Math.max(+start + 864e5, Math.min(+periodEnd, Math.max(+now, lastPoint))));

		chart.setOption(
			{
				animationDuration: 250,
				textStyle: { fontFamily: "Inter, system-ui, sans-serif" },
				grid: { left: 8, right: labelWidth ? Math.min(220, labelWidth + 16) : 16, top: 16, bottom: 8, containLabel: true },
				tooltip: {
					trigger: "item",
					confine: true,
					backgroundColor: "#fff",
					borderColor: "#e3e5e8",
					textStyle: { color: "#0b0b0b", fontSize: 12 },
					formatter: (p) => {
						const d = p.data;
						if (!d || d.tail) return "";
						const r = d.row;
						return `<div style="font-weight:600;margin-bottom:2px">${esc(p.seriesName)}</div>
								<div style="font-size:15px;font-weight:600">${money.format(r.precio)}</div>
								<div style="color:#52514e">${r.inicial ? "Vigente al inicio · desde " : "Desde "}${fmtDay(r.fecha)} ${r.hora}</div>
								${r.inicial ? "" : `<div>${deltaHtml(r.diferencia)}</div>`}
								${r.programado ? '<div style="color:#7a5200">Programado</div>' : ""}`;
					},
				},
				xAxis: {
					type: "time",
					min: start,
					max: axisEnd,
					axisLine: { lineStyle: { color: "#d7d9dd" } },
					axisTick: { show: false },
					axisLabel: { color: "#52514e", hideOverlap: true, formatter: { day: "{d} {MMM}", month: "{MMM} {yyyy}", year: "{yyyy}" } },
					splitLine: { show: false },
				},
				yAxis: {
					type: "value",
					scale: true,
					axisLabel: { color: "#52514e", formatter: (v) => money.format(v) },
					splitLine: { lineStyle: { color: "#eef0f3", width: 1, type: "solid" } },
				},
				series,
			},
			{ notMerge: true }
		);
	}

	// ── detalle de cambios ───────────────────────────────────────
	function fillTable() {
		if (!table) {
			table = $("#fp-history-table").DataTable({
				data: [],
				pageLength: 25,
				order: [[2, "desc"]],
				dom: '<"d-none"B><"d-flex flex-wrap justify-content-between gap-2"f>rt<"d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2"ip>',
				buttons: [
					{
						extend: "excel",
						title: "Histórico de precios de combustibles",
						exportOptions: { columns: [0, 1, 2, 3, 4, 5], modifier: { search: "none" }, orthogonal: "export" },
					},
				],
				columns: [
					{ data: "estacion", render: (d, t) => (t === "display" ? esc(d) : d) },
					{ data: "producto", render: (d, t) => (t === "display" ? esc(d) : d) },
					{
						data: "fecha",
						render: (d, t, r) => {
							if (t === "sort" || t === "type") return r.ts;
							if (t !== "display") return d;
							return `<span class="fp-num">${d}</span>${r.inicial ? '<span class="fp-tag fp-tag-start">Vigente al inicio</span>' : ""}${r.programado ? '<span class="fp-tag fp-tag-future">Programado</span>' : ""}`;
						},
					},
					{
						data: "hora",
						render: (d, t, r) => (t === "sort" || t === "type" ? r.ts : t === "display" ? `<span class="fp-num">${d}</span>` : d),
					},
					{ data: "precio", className: "text-end", render: (d, t) => (t === "display" ? `<span class="fp-num">${money.format(d)}</span>` : d) },
					{
						data: "diferencia",
						className: "text-end",
						render: (d, t, r) => {
							if (t === "sort" || t === "type") return d ?? 0;
							if (t === "export") return r.inicial ? "Vigente al inicio" : d === null ? "" : d;
							return r.inicial ? '<span class="fp-flat">—</span>' : deltaHtml(d);
						},
					},
					{ data: "familia", visible: false, searchable: true },
				],
				language: {
					url: "https://cdn.datatables.net/plug-ins/2.1.2/i18n/es-MX.json",
				},
			});
		}
		table.clear().rows.add(state.data.rows);
		table.column(6).search(`^${state.family}$`, true, false);
		table.draw();
	}

	$("#fp-h-export").on("click", function () {
		if (table) table.button(0).trigger();
	});

	// ── pestañas ─────────────────────────────────────────────────
	$('#fpTabs button[data-bs-toggle="tab"]').on("shown.bs.tab", function (e) {
		const historico = e.target.id === "fp-tab-historico";
		history.replaceState(null, "", historico ? "#historico" : location.pathname + location.search);
		if (historico) {
			if (chart) chart.resize();
			if (table) table.columns.adjust();
		} else if ($.fn.dataTable.isDataTable("#datatable_product_prices")) {
			$("#datatable_product_prices").DataTable().columns.adjust();
		}
	});

	$(function () {
		applyPreset(3);
		if (location.hash === "#historico") {
			$("#fp-tab-historico").trigger("click");
		}
	});
})(jQuery);
