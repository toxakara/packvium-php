<?php
declare(strict_types=1);
namespace Packvium\Result;
enum PackingStatus:string{case Optimal='optimal';case Feasible='feasible';case BestFound='best_found';case TimeLimit='time_limit';case Infeasible='infeasible';case InvalidResult='invalid_result';}
