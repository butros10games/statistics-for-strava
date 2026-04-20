export function v5Theme(): Record<string, unknown> {
    const backgroundColor = 'transparent';
    const gradientColor = ['#f6efa6', '#d88273', '#bf444c'];

    const axisCommon = function (): Record<string, unknown> {
        return {
            axisLine: {
                lineStyle: {
                    color: '#6E7079',
                },
            },
            axisLabel: {
                color: null,
            },
            splitLine: {
                lineStyle: {
                    color: ['#E0E6F1'],
                },
            },
            splitArea: {
                areaStyle: {
                    color: ['rgba(250,250,250,0.2)', 'rgba(210,219,238,0.2)'],
                },
            },
            minorSplitLine: {
                color: '#F4F7FD',
            },
        };
    };

    return {
        backgroundColor,
        color: ['#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de', '#3ba272', '#fc8452', '#9a60b4', '#ea7ccc'],
        gradientColor,
        loading: {
            textColor: 'red',
        },
        bar: {
            defaultBarGap: '20%',
            select: {
                itemStyle: {
                    borderColor: '#212121',
                    borderWidth: 1,
                },
            },
        },
        boxplot: {
            emphasis: {
                itemStyle: {
                    shadowColor: 'rgba(0,0,0,0.2)',
                },
            },
        },
        graph: {
            lineStyle: {
                color: '#aaa',
            },
            select: {
                itemStyle: {
                    borderColor: '#212121',
                },
            },
        },
        heatmap: {
            select: {
                itemStyle: {
                    borderColor: '#212121',
                },
            },
        },
        line: {
            symbolSize: 4,
        },
        pictorialBar: {
            select: {
                itemStyle: {
                    borderColor: '#212121',
                    borderWidth: 1,
                },
            },
        },
        pie: {
            radius: [0, '75%'],
            labelLine: {
                length2: 15,
            },
        },
        map: {
            defaultItemStyleColor: '#eee',
            label: {
                color: '#000',
            },
            itemStyle: {
                borderColor: '#444',
                areaColor: '#eee',
            },
            emphasis: {
                label: {
                    color: 'rgb(100,0,0)',
                },
                itemStyle: {
                    areaColor: 'rgba(255,215,0,0.8)',
                },
            },
            select: {
                label: {
                    color: 'rgb(100,0,0)',
                },
                itemStyle: {
                    color: 'rgba(255,215,0,0.8)',
                },
            },
        },
        timeAxis: axisCommon(),
        logAxis: axisCommon(),
        valueAxis: axisCommon(),
        categoryAxis: (() => {
            const axis = axisCommon();

            return {
                ...axis,
                axisTick: {
                    show: true,
                },
            };
        })(),
        axisPointer: {
            lineStyle: {
                color: '#B9BEC9',
            },
            shadowStyle: {
                color: 'rgba(210,219,238,0.2)',
            },
            label: {
                backgroundColor: 'auto',
                color: '#fff',
            },
            handle: {
                color: '#333',
                shadowBlur: 3,
                shadowColor: '#aaa',
                shadowOffsetX: 0,
                shadowOffsetY: 2,
            },
        },
        brush: {
            brushStyle: {
                color: 'rgba(210,219,238,0.3)',
                borderColor: '#D2DBEE',
            },
            defaultOutOfBrushColor: '#ddd',
        },
        calendar: {
            splitLine: {
                lineStyle: {
                    color: '#000',
                },
            },
            itemStyle: {
                borderColor: '#ccc',
            },
            dayLabel: {
                margin: '50%',
                color: '#000',
            },
            monthLabel: {
                margin: 5,
                color: '#000',
            },
            yearLabel: {
                margin: 30,
                color: '#ccc',
            },
        },
        dataZoom: {
            borderColor: '#d2dbee',
            borderRadius: 3,
            backgroundColor: 'rgba(47,69,84,0)',
            dataBackground: {
                lineStyle: {
                    color: '#d2dbee',
                    width: 0.5,
                },
                areaStyle: {
                    color: '#d2dbee',
                    opacity: 0.2,
                },
            },
            selectedDataBackground: {
                lineStyle: {
                    color: '#8fb0f7',
                    width: 0.5,
                },
                areaStyle: {
                    color: '#8fb0f7',
                    opacity: 0.2,
                },
            },
            handleStyle: {
                color: '#fff',
                borderColor: '#ACB8D1',
            },
            moveHandleStyle: {
                color: '#D2DBEE',
                opacity: 0.7,
            },
            textStyle: {
                color: '#6E7079',
            },
            brushStyle: {
                color: 'rgba(135,175,274,0.15)',
            },
            emphasis: {
                handleStyle: {
                    borderColor: '#8FB0F7',
                },
                moveHandleStyle: {
                    color: '#8FB0F7',
                    opacity: 0.7,
                },
            },
            defaultLocationEdgeGap: 7,
        },
        geo: {
            defaultItemStyleColor: '#eee',
            label: {
                color: '#000',
            },
            itemStyle: {
                borderColor: '#444',
            },
            emphasis: {
                label: {
                    color: 'rgb(100,0,0)',
                },
                itemStyle: {
                    color: 'rgba(255,215,0,0.8)',
                },
            },
            select: {
                label: {
                    color: 'rgb(100,0,0)',
                },
                itemStyle: {
                    color: 'rgba(255,215,0,0.8)',
                },
            },
        },
        grid: {
            left: '10%',
            top: 60,
            bottom: 70,
            borderColor: '#ccc',
        },
        aria: {
            decal: {
                decals: [{color: 'rgba(0, 0, 0, 0.2)'}],
            },
        },
        gauge: {
            title: {
                color: '#464646',
            },
            axisLine: {
                lineStyle: {
                    color: [[1, '#E6EBF8']],
                },
            },
            axisLabel: {
                color: '#6E7079',
            },
            detail: {
                color: '#464646',
            },
        },
        candlestick: {
            itemStyle: {
                color: '#eb5454',
                color0: '#47b262',
                borderColor: '#eb5454',
                borderColor0: '#47b262',
            },
        },
        timeline: {
            lineStyle: {
                color: '#DAE1F5',
                width: 2,
            },
            itemStyle: {
                color: '#A4B1D7',
                borderWidth: 1,
            },
            controlStyle: {
                color: '#A4B1D7',
                borderColor: '#A4B1D7',
                borderWidth: 0.5,
            },
            checkpointStyle: {
                color: '#316BF3',
                borderColor: '#fff',
            },
            label: {
                color: '#A4B1D7',
            },
            emphasis: {
                itemStyle: {
                    color: '#FFF',
                },
                controlStyle: {
                    color: '#A4B1D7',
                    borderColor: '#A4B1D7',
                    borderWidth: 0.5,
                },
                label: {
                    color: '#316BF3',
                },
            },
        },
        radar: {
            itemStyle: {
                color: '#316BF3',
            },
            lineStyle: {
                color: '#316BF3',
            },
            symbolSize: 2,
            symbol: 'emptyCircle',
            smooth: false,
            trigger: 'item',
            axisLine: {
                lineStyle: {
                    color: '#DAE1F5',
                },
            },
            splitLine: {
                lineStyle: {
                    color: '#DAE1F5',
                },
            },
            splitArea: {
                areaStyle: {
                    color: ['#fff'],
                },
            },
            axisName: {
                color: '#6E7079',
            },
        },
        scatter: {
            itemStyle: {
                color: '#316BF3',
            },
        },
        sankey: {
            itemStyle: {
                borderWidth: 0,
                borderColor: '#aaa',
            },
            lineStyle: {
                color: 'source',
                opacity: 0.5,
            },
            emphasis: {
                lineStyle: {
                    opacity: 0.6,
                },
            },
        },
        funnel: {
            itemStyle: {
                borderWidth: 0,
                borderColor: '#aaa',
            },
        },
        legend: {
            top: 0,
            bottom: null,
            backgroundColor: 'rgba(0,0,0,0)',
            borderColor: '#ccc',
            itemGap: 10,
            inactiveColor: '#ccc',
            inactiveBorderColor: '#ccc',
            lineStyle: {
                inactiveColor: '#ccc',
            },
            textStyle: {
                color: '#333',
            },
            selectorLabel: {
                color: '#666',
                borderColor: '#666',
            },
            emphasis: {
                selectorLabel: {
                    color: '#eee',
                    backgroundColor: '#666',
                },
            },
            pageIconColor: '#2f4554',
            pageIconInactiveColor: '#aaa',
            pageTextStyle: {
                color: '#333',
            },
        },
        title: {
            left: 0,
            top: 0,
            backgroundColor: 'rgba(0,0,0,0)',
            borderColor: '#ccc',
            textStyle: {
                color: '#464646',
            },
            subtextStyle: {
                color: '#6E7079',
            },
        },
        toolbox: {
            borderColor: '#ccc',
            padding: 5,
            itemGap: 8,
            iconStyle: {
                borderColor: '#666',
            },
            emphasis: {
                iconStyle: {
                    borderColor: '#3E98C5',
                },
            },
        },
        tooltip: {
            axisPointer: {
                crossStyle: {
                    color: '#999',
                },
            },
            textStyle: {
                color: '#666',
            },
            backgroundColor: '#fff',
            borderWidth: 1,
            defaultBorderColor: '#fff',
        },
        visualMap: {
            color: [gradientColor[2], gradientColor[1], gradientColor[0]],
            inactive: ['rgba(0,0,0,0)'],
            indicatorStyle: {
                shadowColor: 'rgba(0,0,0,0.2)',
            },
            backgroundColor: 'rgba(0,0,0,0)',
            borderColor: '#ccc',
            contentColor: '#5793f3',
            inactiveColor: '#aaa',
            padding: 5,
            textStyle: {
                color: '#333',
            },
        },
    };
}

export function v5DarkTheme(): Record<string, unknown> {
    const base = v5Theme();
    const backgroundColor = 'transparent';
    const contrastColor = '#d4d4d4';

    const axisCommon = function (): Record<string, unknown> {
        return {
            axisLine: {
                lineStyle: {
                    color: contrastColor,
                },
            },
            splitLine: {
                lineStyle: {
                    color: '#333333',
                },
            },
            splitArea: {
                areaStyle: {
                    color: ['rgba(255,255,255,0.02)', 'rgba(255,255,255,0.05)'],
                },
            },
            minorSplitLine: {
                lineStyle: {
                    color: '#2a2a2a',
                },
            },
        };
    };

    return {
        ...base,
        darkMode: true,
        backgroundColor,
        loading: {
            textColor: '#d4d4d4',
        },
        axisPointer: {
            ...(base.axisPointer as Record<string, unknown>),
            lineStyle: {
                color: '#817f91',
            },
            crossStyle: {
                color: '#817f91',
            },
            label: {
                color: '#fff',
            },
        },
        legend: {
            ...(base.legend as Record<string, unknown>),
            textStyle: {
                color: contrastColor,
            },
        },
        textStyle: {
            ...((base.textStyle as Record<string, unknown> | undefined) ?? {}),
            color: contrastColor,
        },
        title: {
            ...(base.title as Record<string, unknown>),
            textStyle: {
                color: '#EEF1FA',
            },
            subtextStyle: {
                color: '#B9B8CE',
            },
        },
        toolbox: {
            ...(base.toolbox as Record<string, unknown>),
            iconStyle: {
                borderColor: contrastColor,
            },
        },
        tooltip: {
            backgroundColor: '#242424',
            borderColor: '#333333',
            textStyle: {color: '#f5f5f5'},
        },
        timeAxis: axisCommon(),
        logAxis: axisCommon(),
        valueAxis: axisCommon(),
        categoryAxis: (() => {
            const axis = axisCommon();

            return {
                ...axis,
                axisTick: {
                    show: true,
                },
            };
        })(),
        grid: {
            ...(base.grid as Record<string, unknown>),
            borderColor: '#333333',
        },
        visualMap: {
            ...(base.visualMap as Record<string, unknown>),
            textStyle: {
                color: contrastColor,
            },
        },
        calendar: {
            ...(base.calendar as Record<string, unknown>),
            itemStyle: {
                color: backgroundColor,
            },
            dayLabel: {
                color: contrastColor,
            },
            monthLabel: {
                color: contrastColor,
            },
            yearLabel: {
                color: contrastColor,
            },
        },
        dataZoom: {
            ...(base.dataZoom as Record<string, unknown>),
            borderColor: '#333333',
            backgroundColor: 'rgba(27,27,27,0)',
            handleStyle: {color: '#d4d4d4', borderColor: '#555555'},
            textStyle: {color: '#d4d4d4'},
            selectedDataBackground: {areaStyle: {color: '#539bf520', opacity: 0.2}},
        },
    };
}